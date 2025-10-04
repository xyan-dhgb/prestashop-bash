# To run files with the same group as your primary user
ARG VERSION="8.1-apache"

# Use the official Composer image as a build stage to avoid downloading the installer during the build
FROM composer:2 AS composer

FROM prestashop/base:$VERSION

# Declare a new time the ARG VERSION
## Because "An ARG declared before a FROM is outside of a build stage, so it can't be used in any instruction after a FROM."
ARG VERSION

EXPOSE 443

ARG GROUP_ID
ARG USER_ID
ARG NODE_VERSION
ARG INSTALL_XDEBUG

RUN groupmod -g $GROUP_ID www-data \
  && usermod -u $USER_ID -g $GROUP_ID www-data

COPY .docker/wait-for-it.sh /tmp/

# Install mailutils and essential tools for composer (git/unzip/zip) to avoid install failures at runtime
RUN apt update \
  && apt install -y --no-install-recommends mailutils git unzip zip ca-certificates \
  && rm -rf /var/lib/apt/lists/*

# Configure git safe directory globally (as root, will work for all users)
RUN git config --global --add safe.directory /var/www/html \
  && git config --global --add safe.directory '*'

ENV NODE_VERSION       $NODE_VERSION

# Copy composer binary from the official composer image (more reliable than downloading during build)
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

RUN if [ "$INSTALL_XDEBUG" = "true" ]; then \
        pecl install xdebug-3.1.3 \
        && docker-php-ext-enable xdebug \
        && echo "xdebug.mode=debug" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
        && echo "xdebug.start_with_request=yes" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini \
        && echo "xdebug.client_host=host.docker.internal" >> /usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini; \
    fi

# Copy custom Apache configuration
COPY .docker/apache-custom.conf /etc/apache2/conf-available/custom.conf
RUN a2enconf custom

COPY .docker/docker_run_git.sh /tmp/
RUN chmod +x /tmp/docker_run_git.sh

CMD ["/tmp/docker_run_git.sh"]