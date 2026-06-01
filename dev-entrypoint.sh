#!/usr/bin/env bash

set -euo pipefail

mkdir -p /root/.ssh /root/.config/git /run/sshd
touch /root/.ssh/authorized_keys /root/.ssh/config /root/.ssh/known_hosts /root/.config/git/config
ln -sf /root/.config/git/config /root/.gitconfig

chmod 700 /root/.ssh

if [[ ! -s /root/.ssh/id_ed25519 ]]; then
    rm -f /root/.ssh/id_ed25519 /root/.ssh/id_ed25519.pub
    ssh-keygen -t ed25519 -N '' -C 'root@helmdesk-dev' -f /root/.ssh/id_ed25519
fi

chmod 600 /root/.ssh/authorized_keys /root/.ssh/config /root/.ssh/id_ed25519
chmod 644 /root/.ssh/id_ed25519.pub /root/.ssh/known_hosts
chmod 600 /root/.config/git/config

ssh-keygen -A

exec /usr/sbin/sshd -D -e \
    -o PasswordAuthentication=no \
    -o KbdInteractiveAuthentication=no \
    -o ChallengeResponseAuthentication=no \
    -o PermitRootLogin=prohibit-password \
    -o PubkeyAuthentication=yes
