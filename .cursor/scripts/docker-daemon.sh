#!/usr/bin/env bash
set -euo pipefail

if docker info >/dev/null 2>&1; then
  exit 0
fi

if ! command -v dockerd >/dev/null 2>&1; then
  echo "dockerd is not installed" >&2
  exit 1
fi

if ! pgrep -x dockerd >/dev/null 2>&1; then
  sudo nohup dockerd --iptables=false --storage-driver=fuse-overlayfs >/tmp/dockerd.log 2>&1 &
  for _ in $(seq 1 30); do
    if docker info >/dev/null 2>&1 || sudo docker info >/dev/null 2>&1; then
      break
    fi
    sleep 1
  done
fi

if docker info >/dev/null 2>&1; then
  exit 0
fi

if sudo docker info >/dev/null 2>&1; then
  exit 0
fi

echo "Docker daemon failed to start. See /tmp/dockerd.log" >&2
exit 1
