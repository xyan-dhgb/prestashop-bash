#!/bin/sh
git config --global --add safe.directory /var/www/html

if [ $PS_ENABLE_SSL = 1 ]; then
  if [ -f ./.docker/ssl.key ]; then
    echo "\n* Remove default-ssl.conf file ...";
    rm /etc/apache2/sites-available/default-ssl.conf

    echo "\n* Enable SSL in Apache ...";
    a2enmod ssl

    echo "\n* Restart apache ...";
    service apache2 restart

    echo "\n* Add virtual host for HTTPS ...";
    echo "<VirtualHost *:443>
  ServerName localhost
  DocumentRoot /var/www/html
  ErrorLog \${APACHE_LOG_DIR}/error.log
  SSLEngine on
  SSLCertificateFile /var/www/html/.docker/ssl.crt
  SSLCertificateKeyFile /var/www/html/.docker/ssl.key
</VirtualHost>" > /etc/apache2/sites-available/001-ssl.conf

    echo "\n* Enable https site"
    a2ensite 001-ssl

    ## Stop Apache process because apache2-foreground will start it
    echo "\n* Stop apache ...";
    service apache2 stop
  else
    echo "\n* The file .docker/ssl.key has not been found.";
  fi
else
  echo "\n* HTTPS is not enabled.";
fi

if [ "${DISABLE_MAKE}" != "1" ]; then
  mkdir -p /var/www/.npm
  chown -R www-data:www-data /var/www/.npm

  echo "\n* Install node $NODE_VERSION...";
  export NVM_DIR=/usr/local/nvm
  mkdir -p $NVM_DIR \
      && curl -o- https://raw.githubusercontent.com/nvm-sh/nvm/v0.39.0/install.sh | bash \
      && . $NVM_DIR/nvm.sh \
      && nvm install $NODE_VERSION \
      && nvm alias default $NODE_VERSION \
      && nvm use default

  export NODE_PATH=$NVM_DIR/versions/node/v$NODE_VERSION/bin
  export PATH=$PATH:$NODE_PATH

  echo "\n* Install composer ...";
  mkdir -p /var/www/.composer
  chown -R www-data:www-data /var/www/.composer
  # Prefer composer binary already available in the image (copied from the official composer image)
  if runuser -g www-data -u www-data -- command -v composer >/dev/null 2>&1; then
    echo "Composer already installed"
  elif [ -f /usr/local/bin/composer ] || [ -f /usr/bin/composer ]; then
    echo "Composer binary found"
  else
    echo "Composer not found, attempting install via php installer..."
    runuser -g www-data -u www-data -- php -r "copy('https://getcomposer.org/installer', '/tmp/composer-setup.php');" || { echo 'Could not download composer installer'; exit 1; }
    runuser -g www-data -u www-data -- php /tmp/composer-setup.php --no-ansi --install-dir=/usr/local/bin --filename=composer || { echo 'Composer installer failed'; rm -f /tmp/composer-setup.php; exit 1; }
    rm -f /tmp/composer-setup.php
  fi

  if [ ! -f /usr/local/bin/composer ] && ! runuser -g www-data -u www-data -- command -v composer >/dev/null 2>&1; then
    echo Composer installation failed
    exit 1
  fi

  echo "\n* Running composer (from /var/www/html) ...";
  # Execute composer as default user so that we can set the env variables to increase timeout, also disable default_socket_timeout for php
  cd /var/www/html || { echo 'Could not cd to /var/www/html'; exit 1; }
  COMPOSER_PROCESS_TIMEOUT=1800 COMPOSER_IPRESOLVE=4 php -ddefault_socket_timeout=-1 /usr/local/bin/composer install --ansi --prefer-dist --no-interaction -vvv 2>&1 | tee /tmp/composer-install.log
  # Update the owner of composer installed folders to be www-data
  chown -R www-data:www-data vendor modules themes || true

  echo "\n* Build assets ...";
  if [ -f ./tools/assets/build.sh ]; then
    runuser -g www-data -u www-data -- /usr/bin/make assets
  else
    echo "./tools/assets/build.sh not found; skipping assets build (make assets will fail)" >&2
  fi

  echo "\n* Wait for assets built...";
  if command -v make >/dev/null 2>&1; then
    /usr/bin/make wait-assets || true
  fi
else
  echo "\n* Build of assets was disabled...";
fi

if [ "$DB_SERVER" = "<to be defined>" -a $PS_INSTALL_AUTO = 1 ]; then
    echo >&2 'error: You requested automatic PrestaShop installation but MySQL server address is not provided '
    echo >&2 '  You need to specify DB_SERVER in order to proceed'
    exit 1
elif [ "$DB_SERVER" != "<to be defined>" -a $PS_INSTALL_AUTO = 1 ]; then
    RET=1
    while [ $RET -ne 0 ]; do
        echo "\n* Checking if $DB_SERVER is available..."
        mysql -h $DB_SERVER -P $DB_PORT -u $DB_USER -p$DB_PASSWD --ssl=0 -e "status" > /dev/null 2>&1
        RET=$?

        if [ $RET -ne 0 ]; then
            echo "\n* Waiting for confirmation of MySQL service startup";
            sleep 5
        fi
    done
        echo "\n* DB server $DB_SERVER is available, let's continue !"
fi

# From now, stop at error
set -e

if [ $PS_DEV_MODE -ne 1 ]; then
  echo "\n* Disabling DEV mode ...";
  sed -i -e "s/define('_PS_MODE_DEV_', true);/define('_PS_MODE_DEV_',\ false);/g" /var/www/html/config/defines.inc.php
else
  echo "\n* Enabling DEV mode ...";
  sed -i -e "s/define('_PS_MODE_DEV_', false);/define('_PS_MODE_DEV_',\ true);/g" /var/www/html/config/defines.inc.php
  echo "\n* Define PHP error logs ...";
  echo "error_log=/var/www/html/var/logs/php.log" >> /usr/local/etc/php/php.ini
fi

if [ ! -f ./app/config/parameters.php ]; then
    if [ $PS_INSTALL_AUTO = 1 ]; then

        echo "\n* Installing PrestaShop, this may take a while ...";

        if [ $PS_ERASE_DB = 1 ]; then
            echo "\n* Drop & recreate mysql database...";
            if [ $DB_PASSWD = "" ]; then
                echo "\n* Dropping existing database $DB_NAME..."
                mysql -h $DB_SERVER -P $DB_PORT -u $DB_USER -e "drop database if exists $DB_NAME;"
                echo "\n* Creating database $DB_NAME..."
                mysqladmin -h $DB_SERVER -P $DB_PORT -u $DB_USER create $DB_NAME --force;
            else
                echo "\n* Dropping existing database $DB_NAME..."
                mysql -h $DB_SERVER -P $DB_PORT -u $DB_USER -p$DB_PASSWD -e "drop database if exists $DB_NAME;"
                echo "\n* Creating database $DB_NAME..."
                mysqladmin -h $DB_SERVER -P $DB_PORT -u $DB_USER -p$DB_PASSWD create $DB_NAME --force;
            fi
        fi

        if [ "$PS_DOMAIN" = "<to be defined>" ]; then
            export PS_DOMAIN=$(hostname -i)
        fi

        echo "\n* Launching the installer script..."
        runuser -g www-data -u www-data -- php /var/www/html/$PS_FOLDER_INSTALL/index_cli.php \
        --domain="$PS_DOMAIN" --db_server=$DB_SERVER:$DB_PORT --db_name="$DB_NAME" --db_user=$DB_USER \
        --db_password=$DB_PASSWD --prefix="$DB_PREFIX" --firstname="Marc" --lastname="Beier" \
        --password="$ADMIN_PASSWD" --email="$ADMIN_MAIL" --language=$PS_LANGUAGE --country=$PS_COUNTRY \
        --all_languages=$PS_ALL_LANGUAGES --newsletter=0 --send_email=0 --ssl=$PS_ENABLE_SSL --fixtures=$PS_INSTALL_DEMO_PRODUCTS

        if [ $? -ne 0 ]; then
            echo 'warning: PrestaShop installation failed.'
        fi
    fi
else
    echo "\n* PrestaShop Core already installed...";
fi

if [ $PS_DEMO_MODE -ne 0 ]; then
    echo "\n* Enabling DEMO mode ...";
    sed -i -e "s/define('_PS_MODE_DEMO_', false);/define('_PS_MODE_DEMO_',\ true);/g" /var/www/html/config/defines.inc.php
fi

if [ $PS_USE_DOCKER_MAILDEV -eq 1 ]; then
    echo "\n* Configuring emails to use maildev ..."
    runuser -g www-data -u www-data -- php /var/www/html/bin/console prestashop:config set PS_MAIL_METHOD --value "2"
    runuser -g www-data -u www-data -- php /var/www/html/bin/console prestashop:config set PS_MAIL_SERVER --value "maildev"
    runuser -g www-data -u www-data -- php /var/www/html/bin/console prestashop:config set PS_MAIL_SMTP_PORT --value "1025"
fi

if [ $BLACKFIRE_ENABLE -eq 1 ]; then
    if [ "$BLACKFIRE_SERVER_ID" = "0" ] || [ "$BLACKFIRE_SERVER_TOKEN" = "0" ]; then
            echo "\n* BLACKFIRE_SERVER_ID and BLACKFIRE_SERVER_TOKEN environment variables missing."
            echo "\n* Skipping blackfire install..."
    else
      echo "\n* Installing Blackfire..."
      wget -q -O - https://packages.blackfire.io/gpg.key | dd of=/usr/share/keyrings/blackfire-archive-keyring.asc
      echo "deb [arch=$(dpkg --print-architecture) signed-by=/usr/share/keyrings/blackfire-archive-keyring.asc] http://packages.blackfire.io/debian any main" | tee /etc/apt/sources.list.d/blackfire.list
      apt update
      apt install -y blackfire
      blackfire agent:config --server-id=$BLACKFIRE_SERVER_ID --server-token=$BLACKFIRE_SERVER_TOKEN
      service blackfire-agent restart
      apt install -y blackfire-php
    fi
fi

echo "\n***"
echo "**"
echo "** Front-office: http://${PS_DOMAIN}/"
echo "**  Back-office: http://${PS_DOMAIN}/admin-dev"
echo "**   Login with:"
echo "**     username: ${ADMIN_MAIL}"
echo "**     password: ${ADMIN_PASSWD}"
if [ $PS_USE_DOCKER_MAILDEV -eq 1 ]; then
    echo "**"
    echo "** To view sent emails point your browser to http://localhost:1080/"
fi
echo "**"
echo "***\n"

echo "\n* Starting web server now\n";

exec apache2-foreground
