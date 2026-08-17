# Backup runner image.
#
# Build context is baja-infra/ (see the `backup` service in
# docker-compose.yml), so `scripts/backup-db.sh` is reachable from here.
#
# Based on mysql:8.4 — the SAME image the stack's server runs — so the
# client and server versions can never drift. Deliberately NOT
# alpine + mariadb-client: MariaDB's client has a history of failing
# against `caching_sha2_password` (the MySQL 8.4 default auth plugin)
# with an error that points at credentials rather than at the plugin,
# which costs an afternoon every time someone hits it.
#
# Everything else the backup needs — curl, gzip, sha256sum, tar, openssl —
# is already in the Oracle Linux 9 base. Only jq, unzip, age and rclone
# have to be added.
FROM mysql:8.4

# Pinned upstream releases. Bump deliberately: change the version AND the
# checksum together, and re-run the acceptance tests in
# docs/backup-restore.md. The checksums are what make the build
# reproducible and what stops a compromised release tarball from
# silently becoming our backup encryptor.
#
# rclone publishes SHA256SUMS with each release; the value below is the
# `rclone-v1.75.0-linux-amd64.zip` line from it.
# age publishes sigsum .proof files rather than a SHA256SUMS list, so the
# value below was computed from the released tarball and pinned here.
#
# The BACKUP_ prefix on these names is load-bearing, not decoration.
# rclone maps every RCLONE_<FLAG> environment variable onto the
# corresponding command-line flag, so an ARG named RCLONE_VERSION is read
# by rclone as `--version=v1.75.0` and the build dies on
# `strconv.ParseBool`. The same trap applies to anything else this image
# ever sets in the environment.
ARG BACKUP_AGE_VERSION=v1.3.1
ARG BACKUP_AGE_SHA256=bdc69c09cbdd6cf8b1f333d372a1f58247b3a33146406333e30c0f26e8f51377
ARG BACKUP_RCLONE_VERSION=v1.75.0
ARG BACKUP_RCLONE_SHA256=aa2804e08f48250e71009c727124b6341cd0288465804a9a09d14663cabafbaa

RUN microdnf -y install jq unzip \
    && microdnf clean all \
    && rm -rf /var/cache/yum

# age — the encryption layer. age-keygen ships alongside and is kept: the
# restore drill (docs/backup-restore.md) uses it to prove a keypair.
RUN set -eux; \
    cd /tmp; \
    curl -fsSL -o age.tar.gz \
        "https://github.com/FiloSottile/age/releases/download/${BACKUP_AGE_VERSION}/age-${BACKUP_AGE_VERSION}-linux-amd64.tar.gz"; \
    echo "${BACKUP_AGE_SHA256}  age.tar.gz" | sha256sum -c -; \
    tar -xzf age.tar.gz; \
    install -m 0755 age/age /usr/local/bin/age; \
    install -m 0755 age/age-keygen /usr/local/bin/age-keygen; \
    rm -rf age age.tar.gz; \
    age --version

# rclone — the Google Drive client. Configured entirely through
# RCLONE_CONFIG_* environment variables (see scripts/backup-db.sh), so no
# rclone.conf is ever written and no credential lands on the image.
RUN set -eux; \
    cd /tmp; \
    curl -fsSL -o rclone.zip \
        "https://github.com/rclone/rclone/releases/download/${BACKUP_RCLONE_VERSION}/rclone-${BACKUP_RCLONE_VERSION}-linux-amd64.zip"; \
    echo "${BACKUP_RCLONE_SHA256}  rclone.zip" | sha256sum -c -; \
    unzip -q rclone.zip; \
    install -m 0755 "rclone-${BACKUP_RCLONE_VERSION}-linux-amd64/rclone" /usr/local/bin/rclone; \
    rm -rf "rclone-${BACKUP_RCLONE_VERSION}-linux-amd64" rclone.zip; \
    rclone version

# backup-lib.sh must land beside backup-db.sh: the script resolves it
# relative to its own location so the same file works from a git checkout
# and from inside the image. It is also what verify-db.sh sources, which
# is the point — the two sides cannot drift.
COPY scripts/backup-lib.sh   /usr/local/bin/backup-lib.sh
COPY scripts/backup-db.sh    /usr/local/bin/backup-db.sh
# Rides along because it needs exactly the same two tools (mysql, jq) and
# is run through this image with --entrypoint. See docs/backup-restore.md.
COPY scripts/erasure-log.sh  /usr/local/bin/erasure-log.sh
RUN chmod 0755 /usr/local/bin/backup-db.sh /usr/local/bin/erasure-log.sh \
    && chmod 0644 /usr/local/bin/backup-lib.sh

# Scratch space for plaintext dumps. Declared here so the intent is
# visible in `docker inspect`; the script re-creates it 0700 on every run
# and wipes it from a trap on every exit path.
ENV BACKUP_WORK_DIR=/var/tmp/baja-backup

# Replaces the mysql image's own entrypoint — this container is a client,
# it never starts a server.
ENTRYPOINT ["/usr/local/bin/backup-db.sh"]
CMD ["run"]
