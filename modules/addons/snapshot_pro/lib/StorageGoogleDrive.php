<?php
/**
 * WHMCS Snapshot Pro - Google Drive Storage Backend
 *
 * Uploads/downloads snapshot archives to Google Drive using the Drive REST API
 * v3 directly over cURL (no external SDK / composer dependency required).
 *
 * Authentication uses a Google service account JSON key. A short-lived OAuth2
 * access token is minted by signing a JWT with the service account private key
 * (RS256) and exchanging it at the Google token endpoint. Uploads use the
 * multipart/related upload endpoint so metadata and content are sent together.
 *
 * @package    SnapshotPro
 * @author     bampu79
 * @license    MIT
 */

namespace SnapshotPro;

use RuntimeException;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly');
}

/**
 * Class StorageGoogleDrive
 *
 * Implements StorageInterface backed by Google Drive. The "reference" for this
 * backend is the Drive file ID returned on upload.
 */
class StorageGoogleDrive implements StorageInterface
{
    /** @var array Parsed service account credentials. */
    private $credentials;

    /** @var string|null Destination Drive folder ID (optional). */
    private $folderId;

    /** @var string|null Cached OAuth2 access token. */
    private $accessToken = null;

    /** @var string OAuth scope needed for Drive file management. */
    const SCOPE = 'https://www.googleapis.com/auth/drive';

    /** @var string Google OAuth2 token endpoint. */
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    /**
     * StorageGoogleDrive constructor.
     *
     * @param string      $serviceAccountJson Raw JSON string of the service account key.
     * @param string|null $folderId           Optional Drive folder ID to upload into.
     *
     * @throws RuntimeException If cURL is unavailable or credentials are invalid.
     */
    public function __construct($serviceAccountJson, $folderId = null)
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('The cURL PHP extension is required for Google Drive storage.');
        }
        if (!function_exists('openssl_sign')) {
            throw new RuntimeException('The OpenSSL PHP extension is required for Google Drive authentication.');
        }
        $decoded = json_decode((string) $serviceAccountJson, true);
        if (!is_array($decoded) || empty($decoded['client_email']) || empty($decoded['private_key'])) {
            throw new RuntimeException('Invalid Google service account JSON: missing client_email or private_key.');
        }
        $this->credentials = $decoded;
        $this->folderId    = $folderId !== null && $folderId !== '' ? $folderId : null;
    }

    /**
     * Upload a local file to Google Drive via multipart upload.
     *
     * @param string $localPath  Path to the source file.
     * @param string $remoteName Name for the Drive file.
     *
     * @return string The Drive file ID (reference).
     *
     * @throws RuntimeException On upload failure.
     */
    public function put($localPath, $remoteName)
    {
        if (!is_readable($localPath)) {
            throw new RuntimeException('Source file not readable: ' . $localPath);
        }
        $token = $this->getAccessToken();

        $metadata = ['name' => basename($remoteName)];
        if ($this->folderId !== null) {
            $metadata['parents'] = [$this->folderId];
        }

        $boundary = 'snapshotpro' . bin2hex(random_bytes(8));
        $fileContents = file_get_contents($localPath);

        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= json_encode($metadata) . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: application/octet-stream\r\n\r\n";
        $body .= $fileContents . "\r\n";
        $body .= "--{$boundary}--";

        $ch = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,size');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 600,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $token,
                'Content-Type: multipart/related; boundary=' . $boundary,
                'Content-Length: ' . strlen($body),
            ],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Google Drive upload cURL error: ' . $curlErr);
        }
        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException('Google Drive upload failed (HTTP ' . $httpCode . '): ' . $response);
        }
        $decoded = json_decode($response, true);
        if (empty($decoded['id'])) {
            throw new RuntimeException('Google Drive upload returned no file ID.');
        }
        return $decoded['id'];
    }

    /**
     * Download a Drive file to a local path.
     *
     * @param string $reference Drive file ID.
     * @param string $localPath Destination path.
     *
     * @return bool
     *
     * @throws RuntimeException On download failure.
     */
    public function get($reference, $localPath)
    {
        $token = $this->getAccessToken();
        $fh = @fopen($localPath, 'wb');
        if (!$fh) {
            throw new RuntimeException('Unable to open local file for Drive download: ' . $localPath);
        }

        $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . rawurlencode($reference) . '?alt=media');
        curl_setopt_array($ch, [
            CURLOPT_FILE       => $fh,
            CURLOPT_TIMEOUT    => 600,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
        ]);
        $ok       = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if ($ok === false || $httpCode < 200 || $httpCode >= 300) {
            @unlink($localPath);
            throw new RuntimeException('Google Drive download failed (HTTP ' . $httpCode . '): ' . $curlErr);
        }
        return true;
    }

    /**
     * Delete a Drive file.
     *
     * @param string $reference Drive file ID.
     *
     * @return bool
     *
     * @throws RuntimeException On failure.
     */
    public function delete($reference)
    {
        $token = $this->getAccessToken();
        $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . rawurlencode($reference));
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => 'DELETE',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 204 = deleted, 404 = already gone (treat as success).
        if ($httpCode === 204 || $httpCode === 404) {
            return true;
        }
        throw new RuntimeException('Google Drive delete failed (HTTP ' . $httpCode . '): ' . $response);
    }

    /**
     * Check whether a Drive file still exists.
     *
     * @param string $reference Drive file ID.
     *
     * @return bool
     */
    public function exists($reference)
    {
        try {
            $token = $this->getAccessToken();
        } catch (RuntimeException $e) {
            return false;
        }
        $ch = curl_init('https://www.googleapis.com/drive/v3/files/' . rawurlencode($reference) . '?fields=id,trashed');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200) {
            return false;
        }
        $decoded = json_decode($response, true);
        return is_array($decoded) && empty($decoded['trashed']);
    }

    /**
     * {@inheritDoc}
     */
    public function getType()
    {
        return 'googledrive';
    }

    /**
     * Obtain (and cache) an OAuth2 access token via the service account JWT flow.
     *
     * @return string Bearer access token.
     *
     * @throws RuntimeException On authentication failure.
     */
    private function getAccessToken()
    {
        if ($this->accessToken !== null) {
            return $this->accessToken;
        }

        $now    = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claim  = [
            'iss'   => $this->credentials['client_email'],
            'scope' => self::SCOPE,
            'aud'   => self::TOKEN_URL,
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header)),
            $this->base64UrlEncode(json_encode($claim)),
        ];
        $signingInput = implode('.', $segments);

        $signature = '';
        $ok = openssl_sign(
            $signingInput,
            $signature,
            $this->credentials['private_key'],
            'sha256WithRSAEncryption'
        );
        if (!$ok) {
            throw new RuntimeException('Failed to sign Google service account JWT.');
        }
        $segments[] = $this->base64UrlEncode($signature);
        $jwt        = implode('.', $segments);

        $postFields = http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]);

        $ch = curl_init(self::TOKEN_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            throw new RuntimeException('Google token request cURL error: ' . $curlErr);
        }
        $decoded = json_decode($response, true);
        if ($httpCode !== 200 || empty($decoded['access_token'])) {
            throw new RuntimeException('Google token exchange failed (HTTP ' . $httpCode . '): ' . $response);
        }

        $this->accessToken = $decoded['access_token'];
        return $this->accessToken;
    }

    /**
     * URL-safe base64 encoding as required by JWT.
     *
     * @param string $data Raw bytes.
     * @return string
     */
    private function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Verify that the configured credentials can authenticate. Useful for the
     * settings page "test connection" button.
     *
     * @return bool True if a token could be obtained.
     */
    public function testConnection()
    {
        $this->accessToken = null;
        $this->getAccessToken();
        return true;
    }
}
