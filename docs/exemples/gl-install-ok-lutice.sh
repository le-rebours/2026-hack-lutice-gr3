#!/bin/bash

# Check if subdomain parameter is provided
if [ "$#" -lt 1 ]; then
    echo "Usage: $0 <subdomain> [uninstall]"
    exit 1
fi

SUBDOMAIN="$1"
ACTION="${2:-install}"
HOST="$SUBDOMAIN.lutice.com"
GL_RELATIVE_URL_ROOT="/"
GL3_DIR="/var/www/greenlight-$SUBDOMAIN"
NGINX_FILES_DEST="/etc/greenlight/nginx"
PGUSER="$SUBDOMAIN"
PGDATABASE="${SUBDOMAIN}_production"
GL_IMG_REPO="bigbluebutton/greenlight:v3"
GL_DEFAULT_PATH="/"
NGINX_ACCESS_LOG="/var/log/nginx/greenlight-$SUBDOMAIN-access.log"
NGINX_ERROR_LOG="/var/log/nginx/greenlight-$SUBDOMAIN-error.log"
BIGBLUEBUTTON_CONFIG="/etc/greenlight/bigbluebutton.yml"
LETS_ENCRYPT_OPTIONS="--standalone --non-interactive"
ASSETS_DEST="$GL3_DIR/assets"
EMAIL="ADMIN_EMAIL"

# Function to display messages
say() {
    echo "[Greenlight $ACTION] $1"
}

# Function to handle errors
err() {
    echo "[ERROR] $1" >&2
    exit 1
}

warn() {
  say "$1" >&2
  EXIT_CODE=1
}

# Installation de Greenlight
install_greenlight() {

  # Create Greenlight directory if it doesn't exist
  sudo mkdir -p "$GL3_DIR"
  sudo chown -R $USER:$USER "$GL3_DIR"

  sudo mkdir -p "$ASSETS_DEST"

  say "pulling latest $GL_IMG_REPO image..."
  docker pull $GL_IMG_REPO

  if [ ! -s $GL3_DIR/docker-compose.yml ]; then
    docker run --rm --entrypoint sh $GL_IMG_REPO -c 'cat docker-compose.yml' > $GL3_DIR/docker-compose.yml

    if [ ! -s $GL3_DIR/docker-compose.yml ]; then
      err "failed to create docker compose file - is docker running?"
    fi

    say "greenlight-v3 docker compose file was created"
  fi

  # Configuring Greenlight v3.
  say "checking the configuration of greenlight-v3..."

  # Configuring Greenlight v3 docker-compose.yml (if configured no side effect will happen).
  sed -i "s|^\([ \t-]*POSTGRES_PASSWORD\)\(=[ \t]*\)$|\1=$(openssl rand -hex 24)|g" $GL3_DIR/docker-compose.yml # Do not overwrite the value if not empty.

  local PGUSER=postgres # Postgres db user to be used by greenlight-v3.
  local PGTXADDR=postgres:5432 # Postgres DB transport address (pair of (@ip:@port)).
  local RSTXADDR=redis:6379 # Redis DB transport address (pair of (@ip:@port)).
  local PGPASSWORD=$(sed -ne "s/^\([ \t-]*POSTGRES_PASSWORD=\)\(.*\)$/\2/p" $GL3_DIR/docker-compose.yml) # Extract generated Postgres password.

  # Configure containerName by subdomain
  sed -i "s/container_name: postgres/container_name: postgres-$SUBDOMAIN/" $GL3_DIR/docker-compose.yml
  sed -i "s/container_name: redis/container_name: redis-$SUBDOMAIN/" $GL3_DIR/docker-compose.yml
  sed -i "s/container_name: greenlight-v3/container_name: greenlight-$SUBDOMAIN/" $GL3_DIR/docker-compose.yml

  if [ -z "$PGPASSWORD" ]; then
    err "failed to retrieve greenlight-v3 DB password - retry to resolve."
  fi

  local DATABASE_URL_ROOT="postgres://$PGUSER:$PGPASSWORD@$PGTXADDR"
  local REDIS_URL_ROOT="redis://$RSTXADDR"

  local PGDBNAME=greenlight-v3-production
  local SECRET_KEY_BASE=$(docker run --rm --entrypoint bundle $GL_IMG_REPO exec rails secret)

  if [ -z "$SECRET_KEY_BASE" ]; then
    err "failed to generate greenlight-v3 secret key base - is docker running?"
  fi

  if [ ! -s $GL3_DIR/.env ]; then
    docker run --rm --entrypoint sh $GL_IMG_REPO -c 'cat sample.env' > $GL3_DIR/.env

    if [ ! -s $GL3_DIR/.env ]; then
      err "failed to create greenlight-v3 .env file - is docker running?"
    fi
 
    say "greenlight-v3 .env file was created"
  fi

  say "Updating .env file in $GL3_DIR..."

  if [ ! -f "$GL3_DIR/.env" ]; then
    err ".env file not found in $GL3_DIR"
  fi

  # Configuring Greenlight v3 .env file (if already configured this will only update the BBB endpoint and secret).
  cp -v $GL3_DIR/.env $GL3_DIR/.env.old && say "old .env file can be retrieved at $GL3_DIR/.env.old" #Backup
 
  # Extract BIGBLUEBUTTON_ENDPOINT and BIGBLUEBUTTON_SECRET from bigbluebutton.yml
  if [ -f "$BIGBLUEBUTTON_CONFIG" ]; then
      BIGBLUEBUTTON_ENDPOINT=$(grep BIGBLUEBUTTON_ENDPOINT "$BIGBLUEBUTTON_CONFIG" | cut -d "'" -f 2)
      BIGBLUEBUTTON_SECRET=$(grep BIGBLUEBUTTON_SECRET "$BIGBLUEBUTTON_CONFIG" | cut -d "=" -f 2)
      sed -i "s|^BIGBLUEBUTTON_ENDPOINT=.*|BIGBLUEBUTTON_ENDPOINT=$BIGBLUEBUTTON_ENDPOINT|" "$GL3_DIR/.env"
      sed -i "s|^BIGBLUEBUTTON_SECRET=.*|BIGBLUEBUTTON_SECRET=$BIGBLUEBUTTON_SECRET|" "$GL3_DIR/.env"
  else
      err "BigBlueButton configuration file not found at $BIGBLUEBUTTON_CONFIG"
  fi

  sed -i "s|^[# \t]*SECRET_KEY_BASE=[ \t]*$|SECRET_KEY_BASE=$SECRET_KEY_BASE|" $GL3_DIR/.env # Do not overwrite the value if not empty.
  sed -i "s|^[# \t]*DATABASE_URL=[ \t]*$|DATABASE_URL=$DATABASE_URL_ROOT/$PGDBNAME|" $GL3_DIR/.env # Do not overwrite the value if not empty.
  sed -i "s|^[# \t]*REDIS_URL=[ \t]*$|REDIS_URL=$REDIS_URL_ROOT/|" $GL3_DIR/.env # Do not overwrite the value if not empty.

# Update .env file catching new configurations:
  if ! grep -q 'RELATIVE_URL_ROOT=' $GL3_DIR/.env; then
      cat <<HERE >> $GL3_DIR/.env
#RELATIVE_URL_ROOT=/gl

HERE
  fi

  if [ -n "$GL_PATH" ]; then
    sed -i "s|^[# \t]*RELATIVE_URL_ROOT=.*|RELATIVE_URL_ROOT=$GL_PATH|" $GL3_DIR/.env
  fi

  local GL_RELATIVE_URL_ROOT=$(sed -ne "s/^\([ \t]*RELATIVE_URL_ROOT=\)\(.*\)$/\2/p" $GL3_DIR/.env) # Extract relative URL root path.
  say "Deploying Greenlight on the '${GL_RELATIVE_URL_ROOT:-$GL_DEFAULT_PATH}' path..."

  if [ -n "$GL_RELATIVE_URL_ROOT" ] && [ "$GL_RELATIVE_URL_ROOT" != "$GL_DEFAULT_PATH" ]; then
    sed -i "s|^\([ \t]*location\)[ \t]*\(.*/cable\)[ \t]*\({\)$|\1 $GL_RELATIVE_URL_ROOT/cable \3|" $NGINX_FILES_DEST/greenlight-$SUBDOMAIN.nginx
    sed -i "s|^\([ \t]*location\)[ \t]*\(@bbb-fe\)[ \t]*\({\)$|\1 $GL_RELATIVE_URL_ROOT \3|" $NGINX_FILES_DEST/greenlight-$SUBDOMAIN.nginx
  fi

# Placing greenlight-v3 nginx file, this will enable greenlight-v3 as your Bigbluebutton frontend (bbb-fe).
  cp -v $NGINX_FILES_DEST/greenlight-v3.nginx $NGINX_FILES_DEST/greenlight-v3.nginx.old && say "old greenlight-v3 nginx config can be retrieved at $NGINX_FILES_DEST/greenlight-v3.nginx.old" #Backup
  docker run --rm --entrypoint sh $GL_IMG_REPO -c 'cat greenlight-v3.nginx' > $NGINX_FILES_DEST/greenlight-v3.nginx && say "added greenlight-v3 nginx file"

  say "Generating certificates for $HOST..."
  say "Rehearsal phase..."
  if certbot --staging --email "$EMAIL" --agree-tos --rsa-key-size 4096 \
        -d "$HOST" --deploy-hook "systemctl reload nginx" $LETS_ENCRYPT_OPTIONS certonly; then
      say "Generating SSL certificates for $HOST..."
      say "Rehearsal passed, ready to issue production certificates!"
      say "Issuing production certificates..."
      if certbot --force-renewal --email "$EMAIL" --agree-tos --rsa-key-size 4096 \
          -d "$HOST" --deploy-hook "systemctl reload nginx" $LETS_ENCRYPT_OPTIONS certonly; then
         say "Production SSL certificates has been generated!"
      else
        warn "Something went wrong when generating production certificates"
      fi
  else
      warn "Unable to pass the rehearsal phase, avoided generating production certificates to keep rates under limit."
  fi  


  say "Generating DH key exchange parameters..."
  cp -v /etc/nginx/ssl/dhp-4096.pem /etc/nginx/ssl/dhp-4096.pem.old

  # For security reasons upon upgrade, the Diffie Hellman key exchange parameters will rotate.
  if ! openssl dhparam -dsaparam  -out /etc/nginx/ssl/dhp-4096.pem 4096; then
    warn "Unable to generate DH key exchange parameters - rolling back..."
    mv -v /etc/nginx/ssl/dhp-4096.pem.old /etc/nginx/ssl/dhp-4096.pem || warn "Unable to generate new DH key exchage parameters nor to recover."
  else
    say "DH key exchange parameters was generated!"
  fi
 
  # HTTP only
  # Updating HTTP config.
  cat <<HERE > /etc/nginx/sites-available/greenlight-$SUBDOMAIN.nginx
server_tokens off;
server {
  listen 80;
  listen [::]:80;
  server_name $HOST;
  return 302 https://\$server_name\$request_uri; #redirect HTTP to HTTPS

}

 server {
  listen 443 ssl http2;
  listen [::]:443 ssl http2;

  ssl_certificate /etc/letsencrypt/live/$HOST/fullchain.pem;
  ssl_certificate_key /etc/letsencrypt/live/$HOST/privkey.pem;
  ssl_session_cache shared:SSL:10m;
  ssl_session_timeout 10m;
  ssl_protocols TLSv1.2 TLSv1.3;
  ssl_ciphers ECDHE-ECDSA-AES128-GCM-SHA256:ECDHE-RSA-AES128-GCM-SHA256:ECDHE-ECDSA-AES256-GCM-SHA384:ECDHE-RSA-AES256-GCM-SHA384:ECDHE-ECDSA-CHACHA20-POLY1305:ECDHE-RSA-CHACHA20-POLY1305:DHE-RSA-AES128-GCM-SHA256:DHE-RSA-AES256-GCM-SHA384;
  ssl_dhparam /etc/nginx/ssl/dhp-4096.pem;



  server_name $HOST;

  # HSTS (comment out to enable)
  #add_header Strict-Transport-Security "max-age=31536000; includeSubDomains" always;
  access_log  $NGINX_ACCESS_LOG;
  error_log  $NGINX_ERROR_LOG;

  # Greenlight landing page.
  location / {
    root   127.0.0.1:5050/assets;
    try_files \$uri @bbb-fe;
  }

  include $NGINX_FILES_DEST/*.nginx;
}

HERE

  if [ ! -f /etc/nginx/sites-enabled/greenlight-$SUBDOMAIN.nginx ]; then
    ln -sf /etc/nginx/sites-available/greenlight-$SUBDOMAIN.nginx /etc/nginx/sites-enabled/greenlight-$SUBDOMAIN.nginx # Activate greenlight nginx config.
  fi

  if [ -f /etc/nginx/sites-enabled/default ]; then
    rm -v /etc/nginx/sites-enabled/default # Remove nginx default config.
  fi

  # Validating the config.
  nginx -qt || warn "Unable to configure nginx - if following the official guides then please contact the maintainers."
  systemctl restart nginx

  # Eager pulling images.
  say "pulling latest greenlight-v3 services images..."
  docker-compose -f $GL3_DIR/docker-compose.yml pull

  if check_container_running greenlight-v3; then
    # Restarting Greenlight-v3 services after updates.
    say "greenlight-v3 is updating..."
    say "shutting down greenlight-v3..."
    docker-compose -f $GL3_DIR/docker-compose.yml down
  fi

  say "starting greenlight-v3..."
  docker-compose -f $GL3_DIR/docker-compose.yml up -d
  sleep 5
  say "Greenlight est maintenant installé et accessible ici : https://$HOST${GL_RELATIVE_URL_ROOT:-$GL_DEFAULT_PATH}"
  say "To create Greenlight administrator account, see: https://docs.bigbluebutton.org/greenlight/v3/install#creating-an-admin-account"

  say "Création du compte admin"
  docker exec -it greenlight-$SUBDOMAIN bundle exec rake admin:create['ADMIN_LOGIN','ADMIN_EMAIL','ADMIN_MDP']
  say "Création du compte utilisateur"
  docker exec -it greenlight-$SUBDOMAIN bundle exec rake user:create['USER_LOGIN','USER_EMAIL','USER_PASSWORD','User']
}

# Désinstallation de Greenlight
uninstall_greenlight() {
    say "Arrêt des conteneurs Docker..."
    cd $GL3_DIR || err "Impossible de se rendre dans $GL3_DIR."
    docker-compose -f $GL3_DIR/docker-compose.yml down

    say "Suppression du répertoire d'installation de Greenlight..."
    rm -rf $GL3_DIR

    say "Suppression de la configuration Nginx..."
    rm -f /etc/nginx/sites-available/greenlight-$SUBDOMAIN.nginx /etc/nginx/sites-enabled/greenlight-$SUBDOMAIN.nginx
    if [ -f /etc/nginx/sites-available/greenlight.old ]; then
        mv /etc/nginx/sites-available/greenlight.old /etc/nginx/sites-available/greenlight
    fi

    say "Révoquation du certificat"
    certbot revoke --cert-path "/etc/letsencrypt/live/$HOST/fullchain.pem" --key-path "/etc/letsencrypt/live/$HOST/privkey.pem" -n


    say "Redémarrage de Nginx..."
    systemctl restart nginx || err "Impossible de redémarrer Nginx."

    say "Greenlight a été désinstallé avec succès."
}

# --- Main Script Logic ---

if [ "$ACTION" = "uninstall" ]; then
    uninstall_greenlight
    exit 0
fi

install_greenlight
