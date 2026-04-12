<?php

namespace Deployer;

require 'recipe/laravel.php';
require 'contrib/npm.php';
require 'contrib/sentry.php';

// Config
set('application', getenv('DEPLOYER_APP'));
set('repository', getenv('DEPLPOYER_REPO'));
set('cleanup_use_sudo', true);
set('keep_releases', 3);
set('writable_mode', 'chmod');

add('shared_files', ['.env']);
add('shared_dirs', ['storage']);
add('writable_dirs', []);

// Hosts
host('prod')
    ->set('remote_user', getenv('DEPLOYER_USER'))
    ->set('hostname', getenv('DEPLOYER_HOSTNAME'))
    ->set('deploy_path', getenv('DEPLOYER_PATH'))
    ->set('bin/php', 'sudo docker exec -t -w {{release_path}} spark php')
    ->set('bin/composer', 'sudo docker exec -t -w {{release_path}} spark composer');

host('dev')
    ->set('remote_user', getenv('DEPLOYER_USER'))
    ->set('branch', 'dev')
    ->set('hostname', getenv('DEPLOYER_HOSTNAME'))
    ->set('deploy_path', getenv('DEPLOYER_PATH'))
    ->set('bin/php', 'sudo docker exec -t -w {{release_path}} spark php')
    ->set('bin/composer', 'sudo docker exec -t -w {{release_path}} spark composer');

// Deploy task sequence
task('deploy', [
    'version:prepare',
    'deploy:prepare',
    'composer:prepare',
    'deploy:vendors',
    'version:set',
    'artisan:storage:link',
    'artisan:view:cache',
    'artisan:config:cache',
    'artisan:migrate',
    'npm:install',
    'npm:run:prod',
    'deploy:publish',      // ← symlink swap happens here
    'octane:reload',       // ← reload Octane workers to pick up new release
    'horizon:restart',     // ← graceful Horizon restart
]);

// Reload Octane gracefully after symlink swap
// octane:reload sends SIGUSR1 to workers — zero-downtime reload
task('octane:reload', function () {
    run('sudo docker exec spark php /var/www/spark/current/artisan octane:reload || sudo docker restart spark');
});

// Terminate Horizon — Docker restart policy brings it back automatically with new code
task('horizon:restart', function () {
    run('sudo docker exec spark-horizon php /var/www/spark/current/artisan horizon:terminate');
});

// Inject Wire credentials before composer install
task('composer:prepare', function () {
    run(
        'sudo docker exec -t -w {{release_path}} spark composer config http-basic.wire-elements-pro.composer.sh %secret%',
        secret: getenv('WIRE_SECRET')
    );
});

// Build frontend assets inside the container
task('npm:run:prod', function () {
    run('sudo docker exec -t -w {{release_path}} spark npm run build');
});

// Version tasks
task('version:prepare', function () {
    $absorb = runLocally('php artisan version:absorb');
    $ver = runLocally('php artisan version:show --format=version-only --suppress-app-name');
    $commit = substr(runLocally('git rev-parse --verify HEAD'), 0, 6);
    set('sentry', [
        'organization' => getenv('SENTRY_ORG'),
        'projects' => getenv('SENTRY_PROJECT_ARRAY'),
        'token' => getenv('SENTRY_TOKEN'),
        'environment' => 'production',
        'version' => $ver . '+' . $commit,
        'version_prefix' => getenv('SENTRY_PREFIX'),
        'sentry_server' => getenv('SENTRY_SERVER'),
    ]);
    set('version', getenv('SENTRY_PREFIX') . $ver . '+' . $commit);
    writeln('<info>' . get('version') . '</info>');
});

task('version:set', function () {
    $ver = get('version');
    run("echo {$ver} > {{release_path}}/VERSION");
    runLocally("echo {$ver} > VERSION.txt");
    run("echo {$ver} > {{release_path}}/public/VERSION.txt");
});

// Upload version.yml after code checkout
after('deploy:update_code', 'deploy:upload_version');
task('deploy:upload_version', function () {
    $localPath = __DIR__ . '/config/version.yml';
    $remotePath = '{{release_path}}/config/version.yml';
    if (! file_exists($localPath)) {
        writeln("<comment>⚠️ version.yml not found locally at {$localPath}, skipping upload.</comment>");

        return;
    }
    writeln('<info>Uploading version.yml to {{release_path}}</info>');
    upload($localPath, $remotePath);
});

// Fix git safe.directory for the repo inside the container
after('deploy:vendors', 'deploy:version:prepare');
task('deploy:version:prepare', function () {
    $repoPath = getenv('DEPLOYER_PATH') . '/.dep/repo';
    run("sudo docker exec -t spark git config --global --add safe.directory {$repoPath}");
});

after('deploy:failed', 'deploy:unlock');
