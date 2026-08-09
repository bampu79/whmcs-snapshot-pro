<?php
/**
 * WHMCS Snapshot Pro - Background Job Queue
 *
 * Persistent queue for snapshot creation. AJAX and DailyCronJob only enqueue;
 * the dedicated CLI worker (cron.php) claims and executes jobs via
 * SnapshotManager so neither the browser nor WHMCS automation cron runs the
 * backup pipeline.
 *
 * Concurrency: an exclusive flock() on a local lock file ensures at most one
 * worker runs SnapshotManager::create() at a time. Holding that lock also
 * allows safe recovery of jobs left "running" by a dead previous worker.
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

namespace SnapshotPro;

use WHMCS\Database\Capsule;
use Throwable;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Class JobQueue
 */
class JobQueue
{
    /** @var string Jobs table name. */
    const TABLE = 'mod_snapshot_pro_jobs';

    const STATUS_QUEUED    = 'queued';
    const STATUS_RUNNING   = 'running';
    const STATUS_COMPLETED = 'completed';
    const STATUS_FAILED    = 'failed';
    const STATUS_CANCELLED = 'cancelled';

    /** Unique schedule_slot value while a scheduled job is queued or running. */
    const SCHEDULE_SLOT_CRON = 'cron';

    /** @var resource|null Open lock file handle while this process holds the worker lock. */
    private static $lockHandle = null;

    /**
     * Ensure the jobs table exists (safe for activate, upgrade, and runtime).
     *
     * @return void
     */
    public static function ensureSchema()
    {
        $schema = Capsule::schema();

        if (!$schema->hasTable(self::TABLE)) {
            $schema->create(self::TABLE, function ($table) {
                $table->increments('id');
                $table->string('job_id', 64)->unique();
                $table->string('status', 20)->default('queued'); // queued|running|completed|failed|cancelled
                $table->string('trigger', 20)->default('manual');
                $table->string('admin_user', 191)->nullable();
                $table->string('snapshot_id', 64)->nullable();
                // At most one active scheduled job: non-null unique while queued/running.
                $table->string('schedule_slot', 32)->nullable()->unique();
                $table->unsignedSmallInteger('progress_percent')->default(0);
                $table->text('progress_message')->nullable();
                $table->text('error')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->dateTime('started_at')->nullable();
                $table->dateTime('completed_at')->nullable();
                $table->index('status');
                $table->index('created_at');
            });
            return;
        }

        // Additive upgrade for installs that created the table before schedule_slot.
        if (!$schema->hasColumn(self::TABLE, 'schedule_slot')) {
            $schema->table(self::TABLE, function ($table) {
                $table->string('schedule_slot', 32)->nullable()->unique();
            });
        }
    }

    /**
     * Path to the exclusive worker lock file.
     *
     * @return string
     */
    public static function workerLockPath()
    {
        $dir = sys_get_temp_dir() . '/snapshot_pro_work';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir . '/worker.lock';
    }

    /**
     * Try to acquire the exclusive non-blocking worker lock (flock).
     *
     * Automatically released when the process exits (or via releaseWorkerLock).
     *
     * @return bool True if this process now owns the lock.
     */
    public static function tryAcquireWorkerLock()
    {
        if (self::$lockHandle !== null) {
            return true;
        }

        $path = self::workerLockPath();
        $fh = @fopen($path, 'c+');
        if ($fh === false) {
            return false;
        }

        if (!@flock($fh, LOCK_EX | LOCK_NB)) {
            fclose($fh);
            return false;
        }

        // Record pid for operators inspecting the lock file (best-effort).
        @ftruncate($fh, 0);
        @fwrite($fh, (string) getmypid());
        @fflush($fh);

        self::$lockHandle = $fh;
        return true;
    }

    /**
     * Release the exclusive worker lock if held by this process.
     *
     * @return void
     */
    public static function releaseWorkerLock()
    {
        if (self::$lockHandle === null) {
            return;
        }
        @flock(self::$lockHandle, LOCK_UN);
        @fclose(self::$lockHandle);
        self::$lockHandle = null;
    }

    /**
     * Absolute path to a job's progress JSON file (shared with the AJAX poller).
     *
     * @param string $jobId
     * @return string
     */
    public static function progressFile($jobId)
    {
        $jobId = preg_replace('/[^a-zA-Z0-9_]/', '', $jobId);
        return sys_get_temp_dir() . '/snapshot_pro_work/progress_' . $jobId . '.json';
    }

    /**
     * Persist progress for browser polling (temp file + optional DB row).
     *
     * @param string   $jobId
     * @param int      $percent
     * @param string   $message
     * @param string   $state   running|done|error (UI poll states)
     * @param array    $extra
     * @param int|null $rowId   Optional jobs table primary key to update.
     * @return void
     */
    public static function writeProgress($jobId, $percent, $message, $state = 'running', array $extra = [], $rowId = null)
    {
        $dir = dirname(self::progressFile($jobId));
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @file_put_contents(self::progressFile($jobId), json_encode(array_merge([
            'percent' => (int) $percent,
            'message' => (string) $message,
            'state'   => $state,
            'ts'      => time(),
        ], $extra)));

        if ($rowId !== null) {
            try {
                Capsule::table(self::TABLE)->where('id', $rowId)->update([
                    'progress_percent'  => (int) $percent,
                    'progress_message'  => (string) $message,
                ]);
            } catch (Throwable $e) {
                // Progress DB updates are best-effort; the file is authoritative for polling.
            }
        }
    }

    /**
     * Redact obvious secrets from exception/diagnostic messages.
     *
     * @param string $message
     * @return string
     */
    public static function sanitizeErrorMessage($message)
    {
        $msg = (string) $message;

        // user:password@host in URIs
        $msg = preg_replace('#(://[^:/@\s]+):([^@/\s]+)@#', '$1:***@', $msg);

        $patterns = [
            '/(password\s*[=:]\s*)(\S+)/i',
            '/(passwd\s*[=:]\s*)(\S+)/i',
            '/(pwd\s*[=:]\s*)(\S+)/i',
            '/(--password=)(\S+)/i',
            '/(api[_-]?key\s*[=:]\s*)(\S+)/i',
            '/(secret(?:_key)?\s*[=:]\s*)(\S+)/i',
            '/(encryption[_-]?key\s*[=:]\s*)(\S+)/i',
            '/(access[_-]?token\s*[=:]\s*)(\S+)/i',
            '/(refresh[_-]?token\s*[=:]\s*)(\S+)/i',
            '/(client[_-]?secret\s*[=:]\s*)(\S+)/i',
            '/(AWS_SECRET_ACCESS_KEY\s*[=:]\s*)(\S+)/i',
            '/(Bearer\s+)([A-Za-z0-9\-._~+\/]+=*)/',
        ];
        foreach ($patterns as $pattern) {
            $msg = preg_replace($pattern, '$1***', $msg);
        }

        return $msg;
    }

    /**
     * Build a progress payload from the jobs table when the temp file is missing.
     *
     * @param string $jobId
     * @return array|null
     */
    public static function progressFromDb($jobId)
    {
        self::ensureSchema();
        $jobId = preg_replace('/[^a-zA-Z0-9_]/', '', $jobId);
        $row = Capsule::table(self::TABLE)->where('job_id', $jobId)->first();
        if (!$row) {
            return null;
        }

        $state = 'running';
        if ($row->status === self::STATUS_COMPLETED) {
            $state = 'done';
        } elseif ($row->status === self::STATUS_FAILED || $row->status === self::STATUS_CANCELLED) {
            $state = 'error';
        }

        $message = (string) ($row->progress_message ?: '');
        if ($message === '' && $row->status === self::STATUS_QUEUED) {
            $message = 'Queued — waiting for the Snapshot Pro CLI worker…';
        }
        if ($state === 'error' && $row->error) {
            $message = (string) $row->error;
        }

        return [
            'percent' => (int) $row->progress_percent,
            'message' => $message,
            'state'   => $state,
            'status'  => $row->status,
            'ts'      => time(),
        ];
    }

    /**
     * Whether a non-terminal job already exists for the given trigger.
     *
     * @param string $trigger
     * @return bool
     */
    public static function hasActiveJob($trigger)
    {
        self::ensureSchema();
        return Capsule::table(self::TABLE)
            ->where('trigger', $trigger)
            ->whereIn('status', [self::STATUS_QUEUED, self::STATUS_RUNNING])
            ->exists();
    }

    /**
     * Detect a unique/duplicate-key database error.
     *
     * @param Throwable $e
     * @return bool
     */
    private static function isDuplicateKeyException(Throwable $e)
    {
        $code = (string) $e->getCode();
        $msg  = $e->getMessage();
        if ($code === '23000' || $code === '1062') {
            return true;
        }
        if (stripos($msg, 'Duplicate') !== false || stripos($msg, 'UNIQUE constraint') !== false) {
            return true;
        }
        $prev = $e->getPrevious();
        return $prev instanceof Throwable ? self::isDuplicateKeyException($prev) : false;
    }

    /**
     * Enqueue a new snapshot job and return its public job id.
     *
     * Does not run SnapshotManager::create().
     *
     * @param string      $trigger
     * @param string|null $adminUser
     * @return string Public job_id for progress polling.
     */
    public static function enqueue($trigger = 'manual', $adminUser = null)
    {
        self::ensureSchema();

        $jobId = 'job_' . bin2hex(random_bytes(6));
        $now   = date('Y-m-d H:i:s');
        $msg   = 'Queued — waiting for the Snapshot Pro CLI worker…';

        $row = [
            'job_id'            => $jobId,
            'status'            => self::STATUS_QUEUED,
            'trigger'           => $trigger,
            'admin_user'        => $adminUser,
            'snapshot_id'       => null,
            'progress_percent'  => 0,
            'progress_message'  => $msg,
            'error'             => null,
            'created_at'        => $now,
            'started_at'        => null,
            'completed_at'      => null,
        ];

        // Only scheduled jobs take the unique active slot (manual jobs stay NULL).
        if ($trigger === 'cron') {
            $row['schedule_slot'] = self::SCHEDULE_SLOT_CRON;
        } else {
            $row['schedule_slot'] = null;
        }

        Capsule::table(self::TABLE)->insert($row);

        self::writeProgress($jobId, 0, $msg, 'running');
        Logger::info('job.enqueue', 'Snapshot job queued: ' . $jobId, $adminUser, ['trigger' => $trigger]);

        return $jobId;
    }

    /**
     * Enqueue a scheduled (cron) snapshot, or return null if one is already active.
     *
     * Uses a unique schedule_slot so two overlapping DailyCronJob processes cannot
     * insert duplicate scheduled jobs (INSERT races become duplicate-key failures).
     *
     * @return string|null Public job_id, or null if a scheduled job is already active.
     */
    public static function enqueueScheduled()
    {
        self::ensureSchema();

        try {
            return self::enqueue('cron', 'cron');
        } catch (Throwable $e) {
            if (self::isDuplicateKeyException($e)) {
                Logger::info(
                    'cron.skip',
                    'Scheduled snapshot not queued: an active scheduled job already exists.',
                    'cron'
                );
                return null;
            }
            throw $e;
        }
    }

    /**
     * Mark jobs left in "running" by a dead worker as failed.
     *
     * Must only be called while this process holds the exclusive worker lock.
     * If we hold the lock, no live worker can be processing a snapshot, so any
     * remaining "running" rows are abandoned.
     *
     * @return void
     */
    public static function recoverAbandonedRunningJobs()
    {
        $msg = 'Snapshot worker terminated unexpectedly before the job completed.';
        $abandoned = Capsule::table(self::TABLE)
            ->where('status', self::STATUS_RUNNING)
            ->orderBy('id')
            ->get();

        foreach ($abandoned as $job) {
            Capsule::table(self::TABLE)->where('id', $job->id)->update([
                'status'           => self::STATUS_FAILED,
                'schedule_slot'    => null,
                'error'            => $msg,
                'progress_percent' => 100,
                'progress_message' => $msg,
                'completed_at'     => date('Y-m-d H:i:s'),
            ]);
            self::writeProgress($job->job_id, 100, $msg, 'error');
            Logger::error(
                'job.recover',
                'Recovered abandoned running job: ' . $job->job_id,
                'cron'
            );
        }
    }

    /**
     * Atomically claim the next queued job (status queued → running).
     *
     * Call only while holding the exclusive worker lock. Same-job double-claim
     * is still prevented by the conditional UPDATE.
     *
     * @return object|null Claimed job row, or null if none available.
     */
    public static function claimNext()
    {
        self::ensureSchema();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $job = Capsule::table(self::TABLE)
                ->where('status', self::STATUS_QUEUED)
                ->orderBy('id')
                ->first();

            if (!$job) {
                return null;
            }

            $now = date('Y-m-d H:i:s');
            $affected = Capsule::table(self::TABLE)
                ->where('id', $job->id)
                ->where('status', self::STATUS_QUEUED)
                ->update([
                    'status'           => self::STATUS_RUNNING,
                    'started_at'       => $now,
                    'progress_percent' => 1,
                    'progress_message' => 'Claimed by CLI worker…',
                ]);

            if ((int) $affected === 1) {
                $claimed = Capsule::table(self::TABLE)->where('id', $job->id)->first();
                if ($claimed) {
                    self::writeProgress($claimed->job_id, 1, 'Claimed by CLI worker…', 'running');
                }
                return $claimed;
            }
            // Lost the race on this row; retry with the next available job.
        }

        return null;
    }

    /**
     * Run SnapshotManager for a claimed job and update terminal status.
     *
     * @param object $job Row from mod_snapshot_pro_jobs.
     * @return bool True on success, false on failure.
     */
    public static function processClaimed($job)
    {
        $jobId     = $job->job_id;
        $rowId     = (int) $job->id;
        $trigger   = $job->trigger ?: 'manual';
        $adminUser = $job->admin_user;

        self::writeProgress($jobId, 2, 'Starting snapshot…', 'running', [], $rowId);
        Logger::info('job.run', 'Processing snapshot job: ' . $jobId, $adminUser ?: 'cron');

        try {
            @set_time_limit(0);
            $manager = new SnapshotManager();
            $meta = $manager->create($trigger, $adminUser, function ($pct, $msg) use ($jobId, $rowId) {
                self::writeProgress($jobId, $pct, $msg, 'running', [], $rowId);
            });
        } catch (Throwable $e) {
            $err = self::sanitizeErrorMessage($e->getMessage());
            try {
                Capsule::table(self::TABLE)->where('id', $rowId)->update([
                    'status'           => self::STATUS_FAILED,
                    'schedule_slot'    => null,
                    'error'            => $err,
                    'progress_percent' => 100,
                    'progress_message' => $err,
                    'completed_at'     => date('Y-m-d H:i:s'),
                ]);
                self::writeProgress($jobId, 100, $err, 'error');
                Logger::error('job.failed', 'Snapshot job failed: ' . $jobId . ' — ' . $err, $adminUser ?: 'cron');
            } catch (Throwable $inner) {
                error_log('SnapshotPro failed to persist job failure for ' . $jobId);
            }
            return false;
        }

        $snapshotId = is_array($meta) && isset($meta['snapshot_id']) ? $meta['snapshot_id'] : null;

        // Persist completed BEFORE any best-effort post-success work.
        try {
            Capsule::table(self::TABLE)->where('id', $rowId)->update([
                'status'           => self::STATUS_COMPLETED,
                'schedule_slot'    => null,
                'snapshot_id'      => $snapshotId,
                'progress_percent' => 100,
                'progress_message' => 'Backup completed successfully.',
                'error'            => null,
                'completed_at'     => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            $err = self::sanitizeErrorMessage(
                'Snapshot finished but job status could not be updated: ' . $e->getMessage()
            );
            try {
                Capsule::table(self::TABLE)->where('id', $rowId)->update([
                    'status'           => self::STATUS_FAILED,
                    'schedule_slot'    => null,
                    'error'            => $err,
                    'progress_percent' => 100,
                    'progress_message' => $err,
                    'completed_at'     => date('Y-m-d H:i:s'),
                ]);
            } catch (Throwable $inner) {
                // ignore
            }
            Logger::error('job.failed', 'Snapshot job status update failed: ' . $jobId . ' — ' . $err, $adminUser ?: 'cron');
            return false;
        }

        // Best-effort only — never revert completed → failed.
        try {
            self::writeProgress($jobId, 100, 'Backup completed successfully.', 'done');
        } catch (Throwable $e) {
            // ignore
        }
        try {
            Logger::success('job.complete', 'Snapshot job completed: ' . $jobId, $adminUser ?: 'cron', [
                'snapshot_id' => $snapshotId,
            ]);
        } catch (Throwable $e) {
            // ignore
        }

        return true;
    }

    /**
     * Claim and process at most one queued job (dedicated CLI worker).
     *
     * Acquires the exclusive worker lock first. If another worker holds it,
     * returns "busy" without processing.
     *
     * @return string One of: busy|idle|completed|failed|error
     */
    public static function processNext()
    {
        if (!self::tryAcquireWorkerLock()) {
            return 'busy';
        }

        try {
            self::ensureSchema();
            self::recoverAbandonedRunningJobs();

            $job = self::claimNext();
            if (!$job) {
                return 'idle';
            }
            return self::processClaimed($job) ? 'completed' : 'failed';
        } catch (Throwable $e) {
            try {
                Logger::error(
                    'job.worker',
                    'Job worker error: ' . self::sanitizeErrorMessage($e->getMessage()),
                    'cron'
                );
            } catch (Throwable $inner) {
                error_log('SnapshotPro JobQueue worker fatal');
            }
            return 'error';
        } finally {
            self::releaseWorkerLock();
        }
    }
}
