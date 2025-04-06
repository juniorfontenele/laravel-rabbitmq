FROM jftecnologia/frankenphp:8.4

ARG WWWUSER_ID
ARG WWWGROUP_ID

# Update user and group ID of user sail
RUN groupmod -g ${WWWGROUP_ID} sail \
    && usermod -u ${WWWUSER_ID} -g ${WWWGROUP_ID} sail

# Install Xdebug
RUN pecl install xdebug \
    && docker-php-ext-enable xdebug \
    && echo "xdebug.mode=debug,develop,coverage,gcstats,profile,trace" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.start_with_request=yes" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.client_port=9003" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.log=/var/log/xdebug.log" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && echo "xdebug.idekey=VSCODE" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
    && touch /var/log/xdebug.log

# Install Github CLI
RUN (type -p wget >/dev/null || (apt update && apt-get install wget -y)) \
    && mkdir -p -m 755 /etc/apt/keyrings \
    && out=$(mktemp) && wget -nv -O$out https://cli.github.com/packages/githubcli-archive-keyring.gpg \
    && cat $out | tee /etc/apt/keyrings/githubcli-archive-keyring.gpg > /dev/null \
    && chmod go+r /etc/apt/keyrings/githubcli-archive-keyring.gpg \
    && echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/githubcli-archive-keyring.gpg] https://cli.github.com/packages stable main" | tee /etc/apt/sources.list.d/github-cli.list > /dev/null \
    && apt update \
    && apt install gh -y

# Install packages
RUN apt-get update \
    && apt-get install -y screen jq \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Create vscode folder
RUN mkdir -p /home/sail/.vscode-server \
    && chown -R sail:sail /home/sail/.vscode-server

# Fix Permissions
RUN chown -R sail:sail /config /home/sail /app /data/caddy /var/log/caddy /var/log/xdebug.log
