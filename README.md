# Coolify Altyapı Yapılandırması ve İşletim Kılavuzu

Bu depo, Coolify yönetim platformunun Docker Compose ile kurulması ve mevcut bir Cloudflare Tunnel üzerinden yayımlanması için gerekli altyapı yapılandırmasını içerir. Alan adı yönetimi, ağ yalıtımı, kalıcı depolama, erişim yönlendirmesi ve yapılandırma yedekleme süreçlerini kapsar.

Yapılandırma, sunucuda çalışan `cloudflared` servisini kullanır. Coolify proxy'si yalnızca `127.0.0.1:18080` üzerinden erişime açılır; böylece sunucunun 80 ve 443 portlarını kullanan mevcut servislerle birlikte çalışabilir. DNS ve tünel kayıtları Cloudflare yönetim panelinden oluşturulur.

## Mimari

Trafik akışı aşağıdaki şekildedir:

`İstemci HTTPS → Cloudflare → cloudflared → 127.0.0.1:18080 → Coolify Traefik proxy → hedef kaynak`

TLS istemci ile Cloudflare arasında sonlandırılır. Aynı sunucudaki tünel konektörü ile Coolify proxy'si arasındaki bağlantı HTTP kullanır.

| Bileşen | Erişim ve işlev |
| --- | --- |
| Coolify yönetim paneli | `127.0.0.1:8000` |
| Gerçek zamanlı bağlantı ve terminal | `127.0.0.1:6001` ve `127.0.0.1:6002` |
| Traefik proxy | `127.0.0.1:18080` |
| Coolify PostgreSQL ve Redis | Host portu yayımlanmadan, `coolify-internal` ağı üzerinden |
| Kaynak ağı | `coolify`, varsayılan alt ağ `192.168.251.0/24` |
| Kalıcı yapılandırma ve veriler | `/data/coolify` |

## Depo yapısı

| Dosya | Açıklama |
| --- | --- |
| `.env.example` | Alan adları, kurulum sırları ve ilk yönetici hesabı için örnek değişkenler |
| `docker-compose.custom.yml` | Resmi Compose tanımlarına eklenen port, ağ, kaynak sınırı ve yönlendirme ayarları |
| `proxy/docker-compose.yml` | Coolify Traefik proxy yapılandırması |
| `scripts/compose.sh` | Etkin Coolify Compose kurulumu için komut arayüzü |
| `scripts/configure-instance.php` | Ortam değişkenlerinin Coolify alan adı ve sunucu ayarlarına uygulanması |
| `scripts/backup.sh` | Coolify yönetim veritabanı ve yapılandırma dosyalarının yedeklenmesi |

Resmi Coolify Compose dosyaları kurulum sırasında `/data/coolify/source` dizinine indirilir. Yerel `.env`, bu dizindeki etkin `.env` dosyasına sembolik bağlantı olarak kullanılır. Gerçek kimlik bilgileri ve ortama özgü değerler sürüm kontrolüne dahil edilmez.

## Ön koşullar

- Ubuntu veya Coolify tarafından desteklenen bir Linux dağıtımı
- Docker Engine **28.0 veya üzeri** ve Docker Compose **2.33.1 veya üzeri**
- OpenSSH, `curl`, Python 3 ve yönetici yetkisi
- DNS yönetimi Cloudflare üzerinde bulunan bir alan adı
- Sunucuda çalışan bir Cloudflare Tunnel konektörü
- Mevcut servislerle çakışmayan port ve ağ adresleri

Kurulum komutları yeni ortamlar için hazırlanmıştır. Mevcut bir kurulumda veritabanı sırlarının veya SSH anahtarlarının yeniden oluşturulması, çalışan hizmetlerde erişim kaybına neden olabilir.

## Ortam değişkenleri

```dotenv
COOLIFY_DOMAIN=panel.example.com
APPS_DOMAIN=example.com
APP_URL="https://${COOLIFY_DOMAIN}"
```

| Değişken | Açıklama |
| --- | --- |
| `COOLIFY_DOMAIN` | Yönetim panelinin alan adı |
| `APPS_DOMAIN` | Kaynakların wildcard alan adı için temel alan adı |
| `APP_URL` | `COOLIFY_DOMAIN` üzerinden oluşturulan dış HTTPS adresi |
| `ROOT_USER_EMAIL`, `ROOT_USER_PASSWORD` | İlk yönetici hesabının oluşturulmasında kullanılan bilgiler |
| `APP_KEY` | Coolify tarafından saklanan hassas değerlerin şifreleme anahtarı |
| `DB_PASSWORD`, `REDIS_PASSWORD` | Coolify yönetim veritabanı ve Redis erişim sırları |
| `LATEST_IMAGE` | Kullanılacak Coolify imaj sürümü |

Alan adları protokol, port veya yol içermemelidir. Gerçek değerler yalnızca `.env` üzerinden yönetilir. Coolify veritabanı ve üretilen proxy dosyaları, bu ayarların çözümlenmiş çalışma kopyalarını içerir.

## Kurulum

### 1. Ortam dosyasının hazırlanması

```bash
cp -n .env.example .env
chmod 600 .env
```

Alan adları ve geçerli yönetici e-posta adresi `.env` dosyasında tanımlanmalıdır. Aşağıdaki komut, yalnızca boş bırakılmış kurulum sırlarını üretir:

```bash
python3 - <<'PYTHON'
import base64, os, secrets
from pathlib import Path
p = Path('.env')
lines = p.read_text().splitlines()
values = {
    'APP_ID': secrets.token_hex(16),
    'APP_KEY': 'base64:' + base64.b64encode(secrets.token_bytes(32)).decode(),
    'DB_PASSWORD': secrets.token_hex(32),
    'REDIS_PASSWORD': secrets.token_hex(32),
    'PUSHER_APP_ID': secrets.token_hex(32),
    'PUSHER_APP_KEY': secrets.token_hex(32),
    'PUSHER_APP_SECRET': secrets.token_hex(32),
    'ROOT_USER_PASSWORD': secrets.token_urlsafe(32) + 'Aa1!',
}
os.umask(0o077)
p.write_text('\n'.join(k + '=' + values[k] if '=' in line and (k := line.split('=', 1)[0]) in values and not line.split('=', 1)[1].strip() else line for line in lines) + '\n')
PYTHON
```

### 2. Kalıcı dizinlerin ve resmi dosyaların hazırlanması

```bash
sudo install -d -m 755 /data/coolify
sudo install -d -o "$(id -u)" -g "$(id -g)" -m 750 /data/coolify/source
for directory in ssh ssh/keys ssh/mux applications databases services backups images; do
  sudo install -d -o 9999 -g 9999 -m 700 "/data/coolify/$directory"
done
sudo install -d -o 9999 -g 9999 -m 755 /data/coolify/proxy /data/coolify/proxy/dynamic
sudo install -o "$(id -u)" -g 9999 -m 640 .env /data/coolify/source/.env
ln -sfn /data/coolify/source/.env .env

for file in docker-compose.yml docker-compose.prod.yml upgrade.sh upgrade-postgres.sh; do
  curl -fsSL "https://cdn.coollabs.io/coolify/$file" -o "/data/coolify/source/$file"
done
chmod +x /data/coolify/source/upgrade.sh /data/coolify/source/upgrade-postgres.sh
cp docker-compose.custom.yml /data/coolify/source/docker-compose.custom.yml
sudo install -o 9999 -g 9999 -m 644 proxy/docker-compose.yml /data/coolify/proxy/docker-compose.yml
```

Özel proxy yapılandırması ilk başlatmadan önce yerleştirilmelidir. Coolify'ın varsayılan proxy ayarları, sunucunun 80 ve 443 portlarını kullanır.

### 3. Kaynak ağının oluşturulması

```bash
docker network create --attachable --subnet 192.168.251.0/24 coolify
```

Ağ mevcutsa `docker network inspect coolify` ile doğrulanmalıdır. Alt ağ değiştirildiğinde SSH yetkilendirmesindeki kaynak ağı ve proxy yapılandırmasındaki güvenilen gateway adresi de güncellenmelidir. `coolify-internal` ağı Compose tarafından oluşturulur.

Proxy'nin `coolify` bağlantısında `gw_priority: 100` tanımlıdır. Bu öncelik, yeni uygulama ağları bağlandığında varsayılan ağ geçidini sabit tutar; tünelden gelen HTTPS başlıklarının güvenilir gateway üzerinden işlenmesini sağlar. Öncelik kaldırılırsa panelde HTTPS yönlendirme döngüsü oluşabilir. Bu özellik için [Docker Engine 28.0](https://docs.docker.com/engine/release-notes/28/#2800) ve [Docker Compose 2.33.1](https://docs.docker.com/reference/compose-file/services/#gw_priority) veya üzeri gerekir.

### 4. Sunucu yönetimi için SSH yetkilendirmesi

```bash
sudo ssh-keygen -q -t ed25519 -N '' -C coolify-local \
  -f /data/coolify/ssh/keys/id.root@host.docker.internal
sudo install -d -m 700 /root/.ssh
sudo touch /root/.ssh/authorized_keys
sudo chmod 600 /root/.ssh/authorized_keys
sudo sh -c 'printf "\nfrom=\"192.168.251.0/24\",no-agent-forwarding,no-port-forwarding,no-X11-forwarding "; cat /data/coolify/ssh/keys/id.root@host.docker.internal.pub' \
  | sudo tee -a /root/.ssh/authorized_keys >/dev/null
sudo chown 9999:9999 /data/coolify/ssh/keys/id.root@host.docker.internal*
```

Anahtar mevcutsa yeniden üretilmemelidir. OpenSSH yapılandırması, root hesabına anahtarlı erişimi desteklemelidir. Yetkilendirme yalnızca tanımlanan kaynak alt ağı için geçerlidir; diğer SSH anahtarları korunmalıdır.

### 5. Servislerin başlatılması

```bash
./scripts/compose.sh config --quiet
./scripts/compose.sh up -d --wait
docker ps --filter name=coolify
```

İlk başlatmada veritabanı migrasyonları, yönetici hesabı oluşturma ve proxy hazırlık işlemleri çalışır. `coolify` ve `coolify-proxy` sağlıklı duruma geldikten sonra ortam ayarları uygulanır:

```bash
docker exec -i coolify php < scripts/configure-instance.php
```

Bu işlem panel alan adını ve wildcard ayarını uygular, genel kullanıcı kaydını kapatır ve sunucuya SSH erişimini doğrular. Otomatik güncelleme politikasını değiştirmez. Dış erişim açılmadan önce ilk yönetici hesabının oluşturulduğu doğrulanmalıdır.

## Cloudflare Tunnel ve DNS yapılandırması

Aşağıdaki yer tutucular `.env` dosyasındaki gerçek değerlerle değiştirilmelidir.

1. Cloudflare yönetim panelinde **Networking → Tunnels** altında mevcut sunucu tüneli açılır.
2. **Routes / Published application routes** bölümüne aşağıdaki yönlendirmeler eklenir:

| Hostname | Origin servis | Path |
| --- | --- | --- |
| `<COOLIFY_DOMAIN>` | `http://127.0.0.1:18080` | Boş |
| `*.<APPS_DOMAIN>` | `http://127.0.0.1:18080` | Boş |

3. **HTTP Host Header** alanı boş bırakılır; yönlendirme, isteğin özgün hostname değeriyle yapılır. WebSocket desteği etkin olmalıdır.
4. **DNS → Records** bölümünde ilgili hostname'ler için proxied CNAME kayıtları doğrulanır. Hedef `<TUNNEL_ID>.cfargotunnel.com` olmalıdır. Wildcard kaydının adı `*` olarak tanımlanır. Otomatik oluşturulmuş kayıtlar tekrar eklenmemelidir.
5. Mevcut tam eşleşen kayıtlar korunur. Wildcard kuralı özel kurallardan sonra ve varsa son 404 kuralından önce konumlandırılır.

Panelin HTTP istekleri, dış bağlantının protokolünü dikkate alan Compose etiketleriyle HTTPS'e yönlendirilir. Origin bağlantısı HTTP olarak kaldığından Coolify panelindeki **Force HTTPS** ayarı kapalı tutulmalıdır.

Kaynaklar için `http://uygulama.<APPS_DOMAIN>` biçimi kullanılabilir; dış erişim `https://uygulama.<APPS_DOMAIN>` üzerinden sağlanır. Coolify'da `https://...` alan adı tanımlandığında ilgili kaynağın **Redirect HTTP to HTTPS** ayarı, HTTP origin bağlantısına uygun biçimde kapatılmalıdır. Uygulamanın dış adres ve güvenli çerez ayarları HTTPS kullanmalıdır.

Özel DNS veya tünel kayıtları wildcard'dan önceliklidir. Alt alan adı derinliği arttığında sertifika kapsamı ayrıca değerlendirilmelidir. SSH, veritabanı ve UDP erişimi ayrı bağlantı yapılandırması gerektirir.

## İlk erişim ve sunucu doğrulaması

Panel adresi `https://<COOLIFY_DOMAIN>` şeklindedir. Etkin adres aşağıdaki komutla görüntülenebilir:

```bash
docker exec coolify printenv APP_URL
```

İlk yönetici hesabında `ROOT_USER_EMAIL` ve `ROOT_USER_PASSWORD` değerleri kullanılır. Bu değişkenler, mevcut hesabın parolasını sonradan değiştirmez. Hesap bilgileri ilk girişte gözden geçirilmeli; e-posta gönderimi için SMTP ayrıca yapılandırılmalıdır.

Kurulum sihirbazında **Choose Server Type → This machine**, mevcut `localhost` sunucusunu kullanır. SSH ve Docker doğrulaması tamamlandıktan sonra Traefik yapılandırması korunmalıdır. Yeni proje veya uygulama oluşturma adımları, altyapı kurulumunun tamamlanması için zorunlu değildir; sihirbazın atlama seçeneği kullanılabilir.

**Servers → localhost → Proxy** bölümünde proxy durumu ve `127.0.0.1:18080:80` eşlemesi doğrulanmalıdır. **IP address or domain** seçeneği, ayrı bir SSH sunucusunun eklenmesi içindir.

## Yapılandırma yönetimi

Alan adı değişiklikleri `.env` üzerinden yapılır. Compose özelleştirmesi değiştirildiğinde etkin kopyaya aktarılır ve ilgili ayarlar yeniden uygulanır:

```bash
cp docker-compose.custom.yml /data/coolify/source/docker-compose.custom.yml
./scripts/compose.sh up -d --no-deps --wait coolify
docker exec -i coolify php < scripts/configure-instance.php
```

Cloudflare kayıtları ayrıca manuel güncellenmelidir. Mevcut kaynakların alan adları, platformun `.env` dosyası değiştirildiğinde kendiliğinden değiştirilmez.

Temel işletim komutları:

```bash
./scripts/compose.sh ps
./scripts/compose.sh logs --tail 100 coolify
./scripts/compose.sh config --quiet
./scripts/compose.sh stop
```

Proxy ve sentinel, ana Compose projesinden ayrı konteynerler olarak yönetilir. Veri volume'lerinin korunması gereken ortamlarda `down -v` kullanılmamalıdır.

## Yedekleme ve kurtarma

```bash
./scripts/backup.sh
# İsteğe bağlı hedef dizin:
./scripts/backup.sh /guvenli/yedek/klasoru
```

Varsayılan hedef `$HOME/backups/coolify` altındadır. Yedek kapsamı:

- Coolify PostgreSQL veritabanı dökümü
- `APP_KEY` dahil etkin `.env` ve Compose dosyaları
- SSH anahtarları ve kaynak yapılandırmaları
- Proxy yapılandırma dosyaları

Dağıtılan uygulamaların veritabanı ve volume içerikleri ayrıca yedeklenmelidir. Yedekler hassas veri içerdiğinden erişim izinleri kısıtlanmalı ve sunucu dışında güvenli bir kopya tutulmalıdır.

Kurtarmada dosyalar aynı yollarına ve uygun sahipliklerle geri yüklenir. PostgreSQL başlatılarak döküm boş Coolify veritabanına `pg_restore` ile aktarılır; ardından Coolify ve proxy başlatılır. Aynı `APP_KEY`, şifrelenmiş değerlerin çözülebilmesi için zorunludur.

## Sürüm ve güncelleme yönetimi

Coolify imaj sürümü `LATEST_IMAGE` üzerinden belirlenir. Güncellemeden önce yapılandırma ve veritabanı yedeği alınmalıdır. Resmi güncelleme yolu `/data/coolify/source/docker-compose.custom.yml` dosyasını korur.

Proxy yapılandırmasının varsayılana sıfırlanması 80/443 port çakışmasını geri getirebilir. Özel port eşlemesi güncellemelerde korunmalıdır. Yönetim PostgreSQL veritabanında ana sürüm değişikliği, uyumluluk doğrulaması ve veri taşıma planı gerektirir; yalnızca imaj etiketi değiştirilerek uygulanmamalıdır.

Disk tüketimi aşağıdaki komutlarla incelenebilir. Temizlik politikaları kullanılmayan imajlar ve build cache için ayrı değerlendirilmelidir; geri dönüş için gerekli imajlar ve kalıcı veriler korunmalıdır.

```bash
docker system df
docker system df -v
```

## DNS ve erişim sorunlarının incelenmesi

`DNS_PROBE_POSSIBLE` veya `NXDOMAIN` hatası, isteğin uygulamaya ulaşmasından önce DNS çözümlemesinde oluşur. Kurum içi DNS ile kamuya açık DNS yanıtları karşılaştırılmalıdır. Aşağıdaki `192.0.2.53` dokümantasyon adresi, ilgili ortamın DNS sunucusuyla değiştirilmelidir.

```bash
panel_domain=$(docker exec coolify printenv COOLIFY_DOMAIN)
lan_dns=192.0.2.53
dig @"$lan_dns" "$panel_domain" A +noedns
dig @1.1.1.1 "$panel_domain" A +noedns
```

Kurum içi DNS eski bir negatif yanıtı önbellekte tutuyorsa sunucu ve gerektiğinde üst DNS önbelleği temizlenmelidir. Windows DNS sunucusunda yönetici oturumu için:

```powershell
Clear-DnsServerCache -Force
```

Windows istemcide `ipconfig /flushdns`, Linux istemcide `sudo resolvectl flush-caches` kullanılabilir. Üst DNS hâlâ `NXDOMAIN` döndürüyorsa yalnızca istemci önbelleğinin temizlenmesi yeterli değildir. Alternatif olarak negatif kaydın TTL süresinin dolması beklenebilir; sorun sürerse DNS yönlendirme ve filtreleme ayarları incelenmelidir.

Yerel DNS'ten bağımsız HTTP/HTTPS doğrulaması:

```bash
panel_edge=$(dig @1.1.1.1 "$panel_domain" A +short | head -n 1)
curl --resolve "$panel_domain:80:$panel_edge" -I "http://$panel_domain/login"
curl --resolve "$panel_domain:443:$panel_edge" -I "https://$panel_domain/login"
```

Beklenen yanıt HTTP için HTTPS'e **301**, HTTPS giriş sayfası için **200**'dür. Cloudflare IP adresleri kalıcı hosts veya DNS kayıtları olarak sabitlenmemelidir.

## Katkı ve bilgi güvenliği

Katkılar pull request üzerinden değerlendirilir. Hata bildirimlerinde örnek alan adları ve temizlenmiş yapılandırmalar kullanılmalıdır. Gerçek `.env` dosyaları, kimlik bilgileri, SSH/Cloudflare anahtarları, yedekler, veritabanı dökümleri, günlükler ve altyapı envanterleri sürüm kontrolüne dahil edilmemelidir. Yayımlama öncesinde içerik ve commit yazarının e-posta adresi kontrol edilmelidir.

## Lisans ve referanslar

Depo [MIT lisansı](LICENSE) ile yayımlanır. Coolify, Traefik ve diğer bağımlılıklar kendi lisanslarına tabidir.

- [Coolify resmi kurulum kılavuzu](https://coolify.io/docs/start-with-self-hosted)
- [Coolify ile Cloudflare Tunnel yapılandırması](https://coolify.io/docs/integrations/networking/cloudflare/tunnels/all-resource)
