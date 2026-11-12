<?php
require '/var/www/html/vendor/autoload.php';
$app = require '/var/www/html/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$domain = getenv('COOLIFY_DOMAIN') ?: '';
$appsDomain = getenv('APPS_DOMAIN') ?: '';
foreach ([$domain, $appsDomain] as $hostname) {
    if (! filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) || ! str_contains($hostname, '.')) {
        throw new InvalidArgumentException('Set valid COOLIFY_DOMAIN and APPS_DOMAIN hostnames in .env');
    }
}
$user = App\Models\User::find(0);
if (! $user) {
    throw new RuntimeException('Bootstrap admin missing: refusing to publish');
}
$settings = App\Models\InstanceSettings::findOrFail(0);
$settings->update([
    'fqdn' => 'https://'.$domain,
    'is_dashboard_force_https_enabled' => false,
    'is_registration_enabled' => false,
]);
$server = App\Models\Server::findOrFail(0);
$server->settings->wildcard_domain = 'http://'.$appsDomain;
$server->settings->save();
$configuration = $server->proxy->get('last_saved_proxy_configuration');
if (! str_contains($configuration ?? '', '127.0.0.1:18080:80')) {
    throw new RuntimeException('Proxy configuration is missing the required loopback port');
}
echo 'Admin exists: '.$user->email.PHP_EOL;
echo 'Public registration disabled; update policy left at default'.PHP_EOL;
$server->setupDynamicProxyConfiguration();
echo instant_remote_process(['id', 'hostname', 'docker version --format "{{.Server.Version}}"'], $server);
echo PHP_EOL;
echo json_encode([
    'server' => $server->name,
    'reachable' => $server->settings->is_reachable,
    'usable' => $server->settings->is_usable,
    'wildcard' => $server->settings->wildcard_domain,
    'proxy_status' => $server->proxy->get('status'),
], JSON_PRETTY_PRINT).PHP_EOL;
