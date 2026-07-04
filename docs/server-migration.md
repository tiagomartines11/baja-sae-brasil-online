# CONFIGURAÇÃO SERVIDOR NOVO BAJA

Guia de configuração com hospedagem na KingHost, criado com base nos testes iniciados em 29/06/2026

## Configuração Sistema

1. Login com usuário root usando chave

2. Criar usuário. Não deixar senha em branco, criar e salvar.

```
adduser baja

usermod -aG sudo baja

mkdir -p /home/baja/.ssh
cp ~/.ssh/authorized_keys /home/baja/.ssh
chown -R baja:baja /home/baja/.ssh
chmod 700 /home/baja/.ssh
chmod 600 /home/baja/.ssh/authorized_keys
```

3. Login com novo usuário usando mesma chave.

4. OPCIONAL - Permitir sudo sem senha no novo usuário

```
sudo visudo

#Add to end
baja ALL=(ALL) NOPASSWD:ALL
```
5. Desabilitar login root

```
sudo nano /etc/ssh/sshd_config

#Alterar para
#PermitRootLogin no

sudo systemctl restart ssh
```

6. Verificar Docker (instalado pelo KingHost)

```
docker --version
docker compose version
systemctl is-active docker
```

Verificar que está ativo e versões são atuais.

Permitir user baja a executar Docker, necessario refazer login após esse comando.

```
sudo usermod -aG docker baja
```

Testar funcionamento Docker

```
sudo docker run hello-world
```

7. Configurar e habilitar Firewall

```
sudo ufw allow OpenSSH
sudo ufw allow 80
sudo ufw allow 443
sudo ufw enable
```

8. Configurar Swap

```
sudo fallocate -l 2G /swapfile
sudo chmod 600 /swapfile
sudo mkswap /swapfile
sudo swapon /swapfile
echo '/swapfile none swap sw 0 0' | sudo tee -a /etc/fstab
```

9. DNS

Configurar DNS para apontar para este servidor, sem proxy por enquanto.

10. Certbot

Instalar Certbot

```
sudo apt install certbot python3-certbot-dns-cloudflare
```

Criar credenciais para cloudflare

```
mkdir -p ~/.secrets
nano ~/.secrets/cloudflare-baja.ini

## Inserir

dns_cloudflare_api_token = TOKEN_API_CONTA_CLOUDFLARE_BAJA
```

Idem para Fórmula:

```
nano ~/.secrets/cloudflare-formula.ini

## Inserir

dns_cloudflare_api_token = TOKEN_API_CONTA_CLOUDFLARE_FORMULA
```

Permissões:

```
chmod 600 ~/.secrets/cloudflare-baja.ini
chmod 600 ~/.secrets/cloudflare-formula.ini
```

Pedir certificados (editar para hostnames utilizados):

```
sudo certbot certonly \
--dns-cloudflare \
--dns-cloudflare-credentials ~/.secrets/cloudflare-baja.ini \
-d bajasaebrasil.com.br \
-d "*.bajasaebrasil.com.br"

sudo certbot certonly \
--dns-cloudflare \
--dns-cloudflare-credentials ~/.secrets/cloudflare-formula.ini \
-d mb3.com.br \
-d "*.mb3.com.br"
```

11. Habilitar Proxy no Cloudflare.

12. Criar diretórios para bind-mount de pastas de dados.

```
sudo mkdir -p /srv/baja/mysql \
	/srv/baja/phpbb-baja/files \
	/srv/baja/phpbb-baja/avatars \
	/srv/baja/phpbb-formula/files \
	/srv/baja/phpbb-formula/avatars
```

13. Instalar Tailscale para acesso remoto seguro

```
curl -fsSL https://tailscale.com/install.sh | sh
sudo tailscale up
tailscale ip -4
```

Inserir o IP retornado em TAILSCALE_IP no .env

## Deploy do GIT

1. Clonar repositório

```
cd ~
git clone https://github.com/tiagomartines11/baja-sae-brasil-online.git
cd baja-sae-brasil-online
cd baja-infra
```

Ou, caso a estrutura containerizada ainda não tenha ido para main:

```
cd ~
git clone -b containerized-monorepo-restructure https://github.com/tiagomartines11/baja-sae-brasil-online.git
cd baja-sae-brasil-online
cd baja-infra
```

2. Ajustar .env

```
cp .env.example .env
nano .env
```

Ajustar conforme necessário

3. Buildar

Builds separados para não exceder capacidades da VM e esbarrar em erros:

```
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml build baja-app
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml build phpbb-baja
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml build phpbb-formula
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml build baja-js
```

4. Up e verificar

```
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d --no-build
sudo docker ps
```

Verificar 9 conatiners inicados corretamente

- baja-nginx
- baja-mysql
- baja-redis
- baja-app
- baja-js
- baja-phpbb-baja
- baja-phpbb-baja-cron
- baja-phpbb-formula
- baja-phpbb-formula-cron

##Migração

0.	Preparar migração

Estando todos containers OK, pode-se iniciar o processo de migração.

```
cd ~/baja-sae-brasil-online/baja-infra/scripts

cp migration.env.example migration.env
nano migration.env
```

Preencher conforme necessário.

Preencher STAGING_BAJA_FORUM_DOMAIN e STAGING_FORMULA_FORUM_DOMAIN com os domínios desejados, isso vai substituir a configuração importada dos Fóruns de produção.

Chave RSA para acesso ao servidor de produção deve estar na nova VM, indicar caminho em PROD_SSH_KEY. Chave deve estar no formato OpenSSH, converter com Puttygen se necessário. O arquivo deve ser ajustado com `chmod 600 xxxx` onde `xxxx` é o nome do arquivo.

VM produção e nova precisam ambos ter Tailscale instalado e estar na mesma Tailnet, IP Tailscale (100.x) deve ser indicado em migration.env.

1.	`preflight`, verifica se variáveis de ambiente foram declaradas, ferramentas necessárias estão disponíveis, chave ssh existe, diretórios de trabalho existem (ou serão criados), pinga tailnet, etc. Deve reportar `preflight: OK`

```
cd ~/baja-sae-brasil-online/baja-infra/scripts
chmod +x migrate.sh

sudo ./migrate.sh preflight
```

2.	`dump`, gera dumb dos bancos de dados de produção. Deve reportar `dump: xxxx (as yyyy)` para cada uma das 3 db e `dump: OK` ao final.

```
sudo ./migrate.sh dump
```

3.	`import`, destroi banco de dados existente (dados de teste do repositório) e importa dados exportados do servidor de produção. Deve reportar `import: OK` ao final. 

```
sudo ./migrate.sh import
```

*Obs: A tabela prova em produção teve a ordem das colunas da PK corrigida (era prova_id, evento_id, agora evento_id, prova_id). Já feito em produção — não refazer. Se import falhar com erro de foreign key em prova, a correção não foi aplicada.*

4.	`patch`, executa várias tarefas:
-	Desabilita emails em ambos os fóruns (email_enable=0, smtp_delivery=0) para evitar que 2 servidores enviem e-mails para usuários reais.
-	Seta `cookie_domain`, `server_name`, `cookie_name` para ambos os fóruns.
-	Adiciona *[TEST]* ao final do nome de cada fórum.
-	Limpa cachês phpbb.
-	Reporta `patch: OK` ao final.

```
sudo ./migrate.sh patch
```

5.	`files`, sincroniza diretórios de arquivos dos dois fóruns da VM produção para a local.

```
sudo ./migrate.sh files
```

6.	`fixup`, ajusta permissões das pastas de arquivos, reinicia containers phpbb, limpa cachês, faz `db:migrate` conforme necessário. Reporta `fixup: OK` ao final.

```
sudo ./migrate.sh fixup
```

7.	`verify`, verifica etapas anteriores e exibe alguns dados dos fóruns para verificação. Reporta contagens e status e-mail ou descreve erros encontrados.

```
sudo ./migrate.sh verify
```

Executar verificações manuais listadas no retorno do script.

## Configurações Manuais e Testes

1.	Substituir e-mails.

⚠️ **ATENÇÃO: Operação destrutiva, somente utilizar em caso de teste, não em migração oficial**

Caso seja desejado testar envio de e-mails sem importunar usuários reais, deve-se substituir os e-mails cadastrados para todos os usuários com um e-mail seguro. Usar queries abaixo, substituir `SAFE_EMAIL` com o e-mail desejado. 

Baja:

```
cd ~/baja-sae-brasil-online/baja-infra
set -a; source ~/baja-sae-brasil-online/baja-infra/.env; set +a
SAFE_EMAIL="you@your-inbox.com"

sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T mysql \
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" phpbb_baja <<SQL
UPDATE phpbb_users SET user_email = '${SAFE_EMAIL}';
UPDATE phpbb_config SET config_value = '${SAFE_EMAIL}' WHERE config_name IN ('board_contact','board_email');
SQL
```

Fórmula:

```
cd ~/baja-sae-brasil-online/baja-infra
set -a; source ~/baja-sae-brasil-online/baja-infra/.env; set +a
SAFE_EMAIL="you@your-inbox.com"

sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T mysql \
mysql -uroot -p"$MYSQL_ROOT_PASSWORD" phpbb_formula <<SQL
UPDATE phpbb_users SET user_email = '${SAFE_EMAIL}';
UPDATE phpbb_config SET config_value = '${SAFE_EMAIL}' WHERE config_name IN ('board_contact','board_email');
SQL
```

Verificar com queries abaixo.

Baja:

```
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T mysql \
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" phpbb_baja \
  -e "SELECT COUNT(DISTINCT user_email) AS distinct_emails, MIN(user_email) AS sample FROM phpbb_users;"
```

Fórmula:

```
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T mysql \
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" phpbb_formula \
  -e "SELECT COUNT(DISTINCT user_email) AS distinct_emails, MIN(user_email) AS sample FROM phpbb_users;"
```

Ambos devem retornar apenas o e-mail escolhido.

2.	Habilitar e-mails

⚠️ **ATENÇÃO: Operação perigosa, executar apenas em caso de migração oficial ou após ter sanitizado conforme passo 7 e verificado que apenas 1 e-mail (o escolhido) consta para cada fórum**

Baja:

```
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T mysql \
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" phpbb_baja \
  -e "UPDATE phpbb_config SET config_value='1' WHERE config_name IN ('email_enable','smtp_delivery');"

sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T phpbb-baja \
  su-exec www-data php bin/phpbbcli.php cache:purge
```

Fórmula:

```
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T mysql \
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" phpbb_formula \
  -e "UPDATE phpbb_config SET config_value='1' WHERE config_name IN ('email_enable','smtp_delivery');"

sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T phpbb-formula \
  su-exec www-data php bin/phpbbcli.php cache:purge
```

Após, acessar painel admin em ambos os fóruns e atualizar dados de autenticação SMTP conforme necessário. Também é necessário whitelistar o IP da nova VM nas configurações SMTP do Google em https://admin.google.com/ac/apps/gmail/routing .

Ao testar envio de e-mails, lembrar que para a maioria dos e-mails é necessário aguardar que o cron envie os e-mails que são colocados na fila, o que acontece a cada 5 minutos.

10.	ReCAPTCHA

Necessário redefinir ReCAPTCHA dos fóruns (Fórmula atualmente não usa). Credenciais podem ser obtidas em https://cloud.google.com/security/products/recaptcha e devem ser configuradas no painel admin em "Medidas de combate à spambots".

## Comandos Úteis

- Status de todos os containers
`sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml ps`

- Uso de recursos (RAM/CPU) por container
`sudo docker stats --no-stream`

- Logs de um container (ex: nginx, mysql, phpbb-baja)
`sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml logs --tail=50 <container>`

- Logs do cron (heartbeat a cada 5 min)
`sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml logs phpbb-baja-cron`
`sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml logs phpbb-formula-cron`

- Rodar cron manualmente (flush imediato da fila de e-mail)
```
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T phpbb-baja \
  su-exec www-data php bin/phpbbcli.php cron:run --verbose
```

- Ver se há e-mails na fila
```
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T phpbb-baja \
  sh -c "ls -la /var/www/html/cache/ | grep -i queue"
```

- Limpar cache phpBB (após mudanças manuais no config do fórum)
```
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T phpbb-baja \
  su-exec www-data php bin/phpbbcli.php cache:purge
```

- Validar config do compose (merge dos dois arquivos)
`sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml config`

- Testar config do nginx
`sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml exec nginx nginx -t`

- Acessar MySQL diretamente
```
set -a; source ~/baja-sae-brasil-online/baja-infra/.env; set +a
sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml exec -T mysql \
  mysql -uroot -p"$MYSQL_ROOT_PASSWORD" <nome_do_banco>
```

- Verificar UID do www-data no container (deve ser 82)
`sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml exec phpbb-baja id www-data`

- Rebuild de uma imagem após mudança (seguido de down -v && up para aplicar)
`sudo docker compose -f docker-compose.yml -f docker-compose.prod.yml build <service>`

- Verificar SAN de um certificado (confirmar apex + wildcard)
`sudo openssl x509 -in /etc/letsencrypt/live/mb3.com.br/fullchain.pem -noout -text | grep -A1 "Subject Alternative Name"`