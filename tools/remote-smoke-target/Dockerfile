FROM ubuntu:24.04

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        ca-certificates \
        curl \
        git \
        openssh-server \
        sudo \
        tar \
        docker.io \
        docker-compose \
    && rm -rf /var/lib/apt/lists/*

RUN mkdir -p /var/run/sshd \
    && useradd -m -s /bin/bash deploy \
    && passwd -d deploy \
    && mkdir -p /home/deploy/.ssh \
    && chown -R deploy:deploy /home/deploy/.ssh \
    && chmod 700 /home/deploy/.ssh \
    && printf 'deploy ALL=(ALL) NOPASSWD:ALL\n' > /etc/sudoers.d/deploy \
    && chmod 0440 /etc/sudoers.d/deploy

COPY entrypoint.sh /usr/local/bin/remote-smoke-entrypoint.sh
RUN chmod +x /usr/local/bin/remote-smoke-entrypoint.sh

EXPOSE 22

ENTRYPOINT ["/usr/local/bin/remote-smoke-entrypoint.sh"]
