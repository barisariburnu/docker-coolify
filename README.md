# docker-coolify

Mevcut Cloudflare Tunnel arkasında Coolify'ı Docker Compose ile çalıştıran kurulum. Alan adlarını ortam değişkenlerinden alır; dış trafiği yalnızca loopback üzerinden kabul eder, PostgreSQL/Redis ağını ayırır ve tünel kayıtları manuel yönetilir.

[MIT lisansı](LICENSE) ile yayımlanır. Coolify, Traefik ve diğer bağımlılıklar kendi lisanslarına tabidir. README'nin açıklama dili Türkçedir; komutlar, değişkenler ve arayüz düğmeleri gerçek adlarıyla yazılır.

Bu yapı, sunucuda çalışan cloudflared servisini kullanır. Trafik `Cloudflare → cloudflared → 127.0.0.1:18080 → Coolify proxy → uygulama` şeklinde ilerler. 80/443 portlarını kullanan diğer servislerle birlikte çalışabilir. Mevcut uygulamaların Coolify'a aktarılması ayrıca yapılır.

## Dosyalar ve tek yapılandırma kaynağı

- `.env`: gerçek alan adları, ilk yönetici bilgileri ve kurulum sırları. Kurulu ortamda `/data/coolify/source/.env` dosyasına sembolik bağlantıdır; ayrı iki kopya tutulmaz.
- `.env.example`: yeni kurulum için örnek değişkenler; gerçek sır içermez.
- `docker-compose.custom.yml`: resmi Compose dosyalarının üzerine uygulanan port, ağ, kaynak sınırı ve alan adı etiketleri.
- `proxy/docker-compose.yml`: yalnızca loopback'e açılan Coolify Traefik proxy'si.
- `scripts/compose.sh`: etkin Compose kurulumu için yardımcı komut.
- `scripts/configure-instance.php`: `.env` değerlerini Coolify panel ve wildcard ayarlarına uygular.
- `scripts/backup.sh`: Coolify yönetim verilerinin yedeğini alır.
- `services/beszel.compose.yml`: Coolify üzerinden kurulacak ilk izleme servisi için hub ve yerel agent tanımı.

Cloudflare API veya tünel/DNS kaydı ekleyen betik yoktur. Bu işlemler aşağıda anlatıldığı gibi panelden manuel yapılır.

```dotenv
COOLIFY_DOMAIN=panel.example.com
APPS_DOMAIN=example.com
APP_URL="https://${COOLIFY_DOMAIN}"
```

İlk iki değişkende yalnızca hostname kullanın; `http://`, `https://`, port veya yol eklemeyin. Gerçek değerleri sadece `.env` içine yazın. Compose etiketleri bu değerleri çözümler; Coolify'ın veritabanı ve `/data/coolify/proxy/dynamic` altındaki üretilen çalışma dosyaları ayarların doğal olarak çözümlenmiş kopyalarını tutar. Bunları elle düzenlemeyin.

## Kurulu ortamın kullanımı

```bash
./scripts/compose.sh ps
./scripts/compose.sh logs --tail 100 coolify
./scripts/compose.sh config --quiet
```

Panel adresi `https://<COOLIFY_DOMAIN>` şeklindedir. Gerçek adresi görmek için:

```bash
docker exec coolify printenv APP_URL
```

İlk giriş için `.env` içindeki `ROOT_USER_EMAIL` ve `ROOT_USER_PASSWORD` kullanılır. Bunlar yalnızca ilk yönetici hesabının oluşturulmasında geçerlidir; mevcut hesabın parolasını `.env` değiştirerek sıfırlayamazsınız. İlk girişte hesabın e-postasını ve parolasını panelden değiştirin. SMTP ayrıca yapılandırılmalıdır; genel kayıt kapalıdır.

### İlk giriş ve onboarding

1. **Choose Server Type → This machine** seçin. Bu seçim, Coolify'ın çalıştığı mevcut `localhost` sunucusunu kullanır. Aynı sunucuyu yeniden IP adresiyle eklemeniz gerekmez.
2. SSH anahtarı ilk kurulumda kaydedilmiştir. Doğrulamanın tamamlanmasını bekleyin. Bu kurulumdaki yeterli Docker sürümü tekrar kurulum gerektirmez. Ekran Docker kurulumunu zorunlu tutarsa önce sunucunun Docker/SSH bağlantısını kontrol edin.
3. Kurulu sürüm, başarılı doğrulamadan sonra **Traefik** seçimini otomatik yapar. Başka bir sürüm proxy sorarsa Traefik seçin. Özel proxy yapılandırmasını koruyun; varsayılana sıfırlamayın.
4. Henüz uygulama kurmayacaksanız kurulum sihirbazını atlayıp kontrol paneline geçin. **Create New Project** boş bir proje oluşturur; bu adım zorunlu değildir.
5. İlk gerçek uygulamanız hazır olduğunda bir proje oluşturup **New Resource → Docker Compose** kullanın. İzleme için ilk servis olarak aşağıdaki Beszel tanımını ekleyebilirsiniz.
6. **Servers → localhost → Proxy** altında proxy'nin çalıştığını kontrol edin. Host portu `127.0.0.1:18080:80` kalmalıdır. Sunucunun **Wildcard Domain** değeri `APPS_DOMAIN` değişkeninden gelir.

**IP address or domain**, başka bir SSH sunucusunu bağlamak içindir. Panelin Cloudflare Tunnel üzerinden yayınlanması, aynı sunucuyu kullanırken onboarding'de SSH için ayrıca bir Cloudflare Tunnel kurulmasını gerektirmez.

### Alan adını değiştirmek

1. `.env` içindeki `COOLIFY_DOMAIN` ve gerekiyorsa `APPS_DOMAIN` değerlerini düzenleyin. `APP_URL` değişken başvurusunu koruyun.
2. Compose özelleştirmesinde de değişiklik yaptıysanız etkin kopyaya aktarın.
3. Panel konteynerini yeniden oluşturup ayarları uygulayın:

```bash
cp docker-compose.custom.yml /data/coolify/source/docker-compose.custom.yml
./scripts/compose.sh up -d --no-deps --wait coolify
docker exec -i coolify php < scripts/configure-instance.php
```

4. Cloudflare tarafında yeni hostname/DNS kayıtlarını manuel güncelleyin. `.env` değişikliği Cloudflare'a kayıt eklemez. Önceden dağıtılmış uygulamaların alan adları da kendiliğinden değiştirilmez.

## Sıfırdan kurulum

Bu bölüm yeni kurulum içindir; çalışan veritabanını veya mevcut sırları yeniden üretmek için kullanılmaz. Ubuntu üzerinde Docker Engine, Compose **2.24.4 veya üzeri**, OpenSSH, curl, Python 3 ve sudo/root erişimi gerekir. Docker daemon'ını yeniden başlatmanız gerekmez. Ağların ve portların mevcut servislerle çakışmadığını kontrol edin.

### 1. Ortam dosyasını hazırlayın

```bash
cp -n .env.example .env
chmod 600 .env
```

`.env` içinde alan adlarını ve gerçek yönetici e-postasını ayarlayın. Aşağıdaki komut yalnızca boş sırları doldurur; mevcut değerleri değiştirmez:

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

### 2. Kalıcı dizinleri ve resmi dosyaları hazırlayın

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

Proxy dosyasını ilk başlatmadan önce yerleştirin. Aksi durumda Coolify varsayılan proxy'si 80/443 portlarını kullanmaya çalışır.

### 3. Uygulama ağını oluşturun

```bash
docker network create --attachable --subnet 192.168.251.0/24 coolify
```

Ağ zaten varsa yeniden oluşturmayın; `docker network inspect coolify` ile kontrol edin. Farklı subnet seçerseniz aşağıdaki SSH yetkisindeki kaynak ağını ve proxy dosyasındaki güvenilen gateway adresini de eşleştirin. PostgreSQL ve Redis, Compose tarafından oluşturulan `coolify-internal` ağına bağlıdır; host portları yoktur.

### 4. Coolify için özel SSH anahtarı oluşturun

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

Mevcut özel anahtarın üzerine yazmayın veya yetki satırını tekrar eklemeyin. OpenSSH'in root hesabına anahtarlı girişe izin vermesi gerekir. Mevcut SSH ayarları ve diğer yetkili anahtarlar korunmalıdır.

### 5. Başlatın ve ayarları uygulayın

```bash
./scripts/compose.sh config --quiet
./scripts/compose.sh up -d --wait

docker ps --filter name=coolify
```

İlk açılışta migrasyon, yönetici oluşturma ve proxy başlatma işleri çalışır. `coolify` ve `coolify-proxy` sağlıklı olduğunda:

```bash
docker exec -i coolify php < scripts/configure-instance.php
```

Bu işlem alan adını ve wildcard ayarını ortam değişkenlerinden uygular, genel kaydı kapatır ve sunucuya SSH erişimini doğrular. Otomatik güncelleme politikasını değiştirmez. İlk hesabın oluştuğunu doğrulamadan panel tünelini yayınlamayın.

## Cloudflare kayıtlarını manuel ekleme

Aşağıdaki `<COOLIFY_DOMAIN>` ve `<APPS_DOMAIN>` ifadeleri, `.env` dosyasındaki gerçek değerlerle değiştirilmelidir.

1. Cloudflare'da **Networking → Tunnels** bölümünden bu sunucuda çalışan mevcut tüneli seçin. Yeni bir konektör kurmanız gerekmez.
2. **Routes / Published application routes** bölümünde iki hostname ekleyin:

| Hostname | Origin servis | Path |
| --- | --- | --- |
| `<COOLIFY_DOMAIN>` | `http://127.0.0.1:18080` | Boş |
| `*.<APPS_DOMAIN>` | `http://127.0.0.1:18080` | Boş |

3. Origin **HTTP Host Header** alanını boş bırakın; uygulama seçimi ziyaretçinin orijinal hostname'iyle yapılır. WebSocket desteği açık olmalıdır.
4. **DNS → Records** altında her hostname için proxied CNAME kaydını kontrol edin. Hedef `<TUNNEL_ID>.cfargotunnel.com` olmalıdır. Wildcard kaydının adı `*` olur. Arayüz kaydı otomatik oluşturduysa ikinci kayıt eklemeyin; var olan farklı bir kaydı incelemeden değiştirmeyin.
5. Mevcut tam eşleşen uygulama kurallarını ve diğer DNS kayıtlarını koruyun. Yeni wildcard kuralını mevcut özel kurallardan sonra ve varsa son 404 kuralından önce konumlandırın.

HTTP panel istekleri HTTPS'e yönlendirilir. Ziyaretçi HTTPS kullanırken tünelin origin bağlantısı HTTP olarak kalır. Coolify panelindeki **Force HTTPS** ayarını açmayın; HTTPS'e geçiş, dış bağlantının protokolünü dikkate alan Compose etiketleriyle yapılır. İsterseniz Cloudflare'da yalnızca panel hostname'ine özel HTTPS yönlendirme kuralı da tanımlayabilirsiniz.

### Yeni uygulamaların alt alan adları

Coolify'da uygulamaya `http://uygulama.<APPS_DOMAIN>` alan adı ve gerçek konteyner portunu verin. Ziyaretçi `https://uygulama.<APPS_DOMAIN>` kullanır. Coolify'a `https://...` giriyorsanız ilgili kaynağın **Redirect HTTP to HTTPS** ayarını kapatın; HTTP origin üzerinden yönlendirme döngüsü oluşmasın. Uygulamanın dış URL ve güvenli çerez ayarları HTTPS kullanmalıdır.

Yeni, boş bir alt alan adı için ayrı tünel kuralı gerekmez. Mevcut tam eşleşen DNS/tünel kayıtları wildcard'dan önceliklidir. Mevcut uygulama taşındığında onun eski kuralını da proxy'ye yönlendirin. Daha derin hostname'lerde Cloudflare sertifika kapsamını ayrıca kontrol edin. SSH, veritabanı ve UDP servisleri için ayrı bağlantı tasarımı gerekir.

## DNS_PROBE_POSSIBLE / DNS adresi bulunamadı

Bu hata, HTTP isteği sunucuya ulaşmadan önce DNS çözümlemesinde oluşur. Kurum içi DNS eski bir **NXDOMAIN** yanıtını önbellekte tutarken kamuya açık DNS doğru IP döndürebilir. İki kaynağı karşılaştırın. Aşağıdaki DNS sunucusu adresi dokümantasyon örneğidir; kendi DNS adresinizle değiştirin.

```bash
panel_domain=$(docker exec coolify printenv COOLIFY_DOMAIN)
lan_dns=192.0.2.53 # Örnek adres; kendi DNS sunucunuzu yazın.
dig @"$lan_dns" "$panel_domain" A +noedns
dig @1.1.1.1 "$panel_domain" A +noedns
```

Kurum içi DNS yöneticisi, kullanılan DNS sunucusundaki negatif DNS önbelleğini ve varsa onun kullandığı üst DNS önbelleğini temizlemelidir. DNS sunucusu Windows DNS ise yönetici oturumunda:

```powershell
Clear-DnsServerCache -Force
```

Ardından Windows istemcide:

```powershell
ipconfig /flushdns
```

Linux istemcide `sudo resolvectl flush-caches` kullanılabilir. **Yalnızca istemci önbelleğini temizlemek, üst DNS hâlâ NXDOMAIN döndürüyorsa yeterli değildir.** Alternatif olarak negatif kaydın TTL süresinin dolması beklenebilir. Hata devam ederse DNS sunucusunun forwarder/filtreleme ayarlarını kontrol edin. DNS düzelene kadar port veya tünel kaydını değiştirmek bu hatayı çözmez.

DNS'ten bağımsız, TLS sertifikasını da doğrulayan dış erişim testi:

```bash
panel_edge=$(dig @1.1.1.1 "$panel_domain" A +short | head -n 1)
curl --resolve "$panel_domain:80:$panel_edge" -I "http://$panel_domain/login"
curl --resolve "$panel_domain:443:$panel_edge" -I "https://$panel_domain/login"
```

Beklenen sonuç HTTP için HTTPS'e **301**, HTTPS giriş sayfası için **200**'dür. Cloudflare IP'lerini kalıcı hosts/DNS kaydı olarak sabitlemeyin.

## Yedek, güncelleme ve taşıma

```bash
./scripts/backup.sh
# Veya hedef klasörü kendiniz verin:
./scripts/backup.sh /guvenli/yedek/klasoru
```

Varsayılan yedek konumu `$HOME/backups/coolify` altıdır. Yedek, Coolify PostgreSQL dump'ını, aynı APP_KEY dahil `.env`/Compose dosyalarını, SSH ve uygulama yapılandırmalarını, proxy dosyalarını içerir. **Uygulamaların gerçek veritabanları ve volume verileri bu yedeğe dahil değildir.** Sır içeren yedekleri erişimi kısıtlı tutun ve sunucu dışında saklayın.

Kurtarma için kaynak dosyaları aynı yollarına geri koyun; yalnızca PostgreSQL'i başlatıp dump'ı boş Coolify veritabanına `pg_restore` ile yükleyin; ardından Coolify ve proxy'yi başlatın. Aynı APP_KEY olmadan şifrelenmiş değerler çözülemez. Dosya sahipliklerini yukarıdaki kurulumla eşleştirin.

Resmi güncelleme yolu `/data/coolify/source/docker-compose.custom.yml` dosyasını korur. Güncellemeden önce yedek alın. Proxy'yi varsayılana sıfırlamak 80/443 çakışmasını geri getirir; `127.0.0.1:18080:80` eşlemesini koruyun. Coolify sunucu ayarlarında otomatik Docker temizliği politikasını kullanımınıza göre gözden geçirin. Mevcut uygulamaların verilerini/imajlarını topluca temizlemeyin.

Mevcut Compose projelerini taşırken önce kaynak dosyalarını, ortam değişkenlerini ve kalıcı volume/bind mount'larını envanterleyip veritabanı yedeği alın. Coolify'da external volume veya mutlak bind yollarını açıkça tanımlayın; yeni proje adıyla boş volume oluşmasını önleyin. Önce farklı bir test alt alan adında doğrulayın, sonra ilgili tünel kuralını proxy'ye taşıyın. Aynı veritabanı dosyasını iki veritabanı konteynerinde eşzamanlı açmayın. Çalışan bir konteyneri yalnızca proxy'ye bağlamak deploy yaşam döngüsünü Coolify'a devretmez.

Kurulumu durdurmak için `./scripts/compose.sh stop` kullanılabilir. Proxy/sentinel ayrı konteynerlerdir. Veri silmek istemiyorsanız `down -v` kullanmayın.

Kaynaklar: [Resmi kurulum](https://coolify.io/docs/start-with-self-hosted), [Cloudflare Tunnel ile tüm kaynaklar](https://coolify.io/docs/integrations/networking/cloudflare/tunnels/all-resource).

## Beszel ile sunucu izleme

Beszel, hub ve agent bileşenlerinden oluşur. Kendi SQLite veritabanını kullanır; PostgreSQL servisi gerektirmez. İki bileşen aynı `BESZEL_VERSION` sürümüne sabitlenmiştir.

1. Bir **İzleme** projesi oluşturun. Sihirbazın oluşturduğu proje boşsa onu yeniden adlandırabilirsiniz.
2. **New Resource → Docker Compose** üzerinden `services/beszel.compose.yml` içeriğini ekleyin.
3. Servisin **Environment Variables** bölümünde `BESZEL_DOMAIN`, `BESZEL_VERSION`, `BESZEL_USER_EMAIL` ve güçlü bir `BESZEL_USER_PASSWORD` tanımlayın. `.env.example` yalnızca örnektir; kök `.env` değerleri Coolify uygulamalarına kendiliğinden aktarılmaz.
4. `beszel` bileşeninin alan adını `http://<BESZEL_DOMAIN>:8090` olarak ayarlayın. HTTP→HTTPS zorlamasını kapalı tutun; ziyaretçiler `https://<BESZEL_DOMAIN>` kullanır. `APP_URL` dış HTTPS adresini kullanır.
5. Hub ilk kullanıcıyı ortam değerlerinden oluşturur. İlk bağlantıda hesabın hazır olduğunu doğrulayın ve parolayı değiştirin. İlk kullanıcı değişkenleri mevcut hesabın parolasını sonradan değiştirmez.
6. Beszel'de **Add System** ile host alanına `/beszel_socket/beszel.sock` girin. Hub'ın gösterdiği public key ve token'ı Coolify servisindeki `BESZEL_AGENT_KEY` / `BESZEL_AGENT_TOKEN` değerlerine kaydedip servisi yeniden dağıtın.
7. Sistemin çevrimiçi olduğunu ve CPU, bellek, disk ve konteyner metriklerinin geldiğini kontrol edin.

Agent, host ağındaki istatistikleri okuyabilmek için `network_mode: host` kullanır; bağlantı Unix socket üzerinden yapıldığından ayrıca TCP portu açılmaz. Docker metrikleri için Docker socket'i salt okunur bağlanır; bu agent'in ayrıcalıklı Docker erişimi gerektirdiğini gözetin. `beszel_data` veritabanını ve `beszel_agent_data` agent durumunu kalıcı saklar. Uygulama verilerini de kapsayan ayrıca bir Beszel yedeği alın.

Mevcut wildcard tünel kaydı yeni alan adını proxy'ye iletir. Önceden aynı hostname için özel bir DNS/tünel kaydı varsa manuel kontrol edin. Bu depoda Cloudflare kaydı oluşturan otomasyon bulunmaz.

Kaynak: [Beszel başlangıç kılavuzu](https://beszel.dev/guide/getting-started), [ortam değişkenleri](https://beszel.dev/guide/environment-variables).

## Ortak imaj ve sürüm politikası

Önerilen varsayılan: **aynı uyumlu imaj sürümü, uygulama başına ayrı konteyner ve ayrı veri volume'ü**. Docker, aynı imajı ve aynı katmanları disk üzerinde paylaşır; her konteyner için imajın tamamını tekrar indirmez. Aynı imajı kullanmak veritabanlarını, parolaları veya volume'leri ortaklaştırmaz.

- Uyumlu yeni projeler için bir PostgreSQL ana sürümü ve dağıtım türü seçin. `postgres:<sürüm>-alpine` ile Debian tabanlı varyant aynı imaj değildir; gereken eklentiler de uyumlu olmalıdır.
- Aynı tam sürüm etiketini ve tercihen `@sha256:<digest>` değerini kullanın. `latest` kullanmayın. Digest sabitlemesi yeni güvenlik yamalarını otomatik getirmez; yamaları düzenli inceleyip planlı güncelleyin.
- Coolify **Shared Variables → Team wide** altında örneğin `POSTGRES_IMAGE` tutabilirsiniz. Her serviste `POSTGRES_IMAGE={{team.POSTGRES_IMAGE}}` başvurusunu açıkça ekleyin ve Compose içinde `image: ${POSTGRES_IMAGE}` kullanın. Ortak değişken kendiliğinden tüm servislere uygulanmaz; değişiklik ilgili yeniden dağıtımda etkinleşir.
- Coolify'ın kendi veritabanını ve sürüm/eklenti zorunluluğu bulunan uygulamaları bu politikadan ayrı değerlendirin. PostGIS gibi eklentiler düz PostgreSQL imajıyla aynı işlevi sunmaz.
- Çalışan PostgreSQL 14/15/16/17 verilerini tek ana sürüme geçirmek, yalnızca imaj adını değiştirmekle yapılmaz. Uygulama ve eklenti uyumluluğunu doğrulayın; yedek, test, `pg_upgrade` veya mantıksal dump/restore ve geri dönüş planı hazırlayın.
- Aynı yaklaşım Redis, Nginx ve Node taban imajlarında da uygulanabilir. Farklı uygulamaların kendi kod katmanları yine ayrı kalır; imaj sayısı tek başına disk tüketimini göstermez.

Tek bir PostgreSQL sunucusunda uygulama başına ayrı veritabanı/rol kullanmak, RAM ve süreç sayısını azaltan bir alternatiftir. Buna karşılık kesinti, kaynak rekabeti ve sürüm yükseltmeleri tüm uygulamaları etkiler. Farklı bağımlılıkları olan mevcut projeler için başlangıç tercihi olarak ayrı konteynerler daha kolay yönetilir.

İmaj değiştirmeden önce gerçek tüketimi ölçün:

```bash
docker system df
docker system df -v
docker buildx du
```

Build cache ile kullanılmayan imajları ayrı değerlendirin. Yalnızca gereksiz olduğu doğrulanan eski cache/imagelar için yaş ve kullanım sınırıyla temizlik yapın; geri dönüş için gereken imajları koruyun. Volume'leri temizlik hedefi haline getirmeyin.

Kaynaklar: [Docker imaj katmanları](https://docs.docker.com/get-started/docker-concepts/building-images/understanding-image-layers/), [PostgreSQL ana sürüm yükseltme](https://www.postgresql.org/docs/current/upgrading.html), [Coolify ortak değişkenleri](https://coolify.io/docs/core/team/shared-variables).

## Katkı ve gizlilik

Katkılarınızı pull request ile gönderebilirsiniz. Bir sorunu paylaşırken örnek alan adları içeren `.env.example` kullanın. Gerçek ortam dosyalarını, kimlik bilgilerini, SSH/Cloudflare anahtarlarını, yedekleri, veritabanı dökümlerini, günlükleri ve altyapı envanterini Git dışında tutun. Yayımlamadan önce dosya içeriklerini ve commit yazarının e-posta adresini kontrol edin.
