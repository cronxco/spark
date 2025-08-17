<?php

namespace Deployer;

require 'recipe/laravel.php';
require 'contrib/php-fpm.php';
require 'contrib/npm.php';
require 'contrib/crontab.php';
require 'contrib/sentry.php';

// Config
set('application', getenv('DEPLOYER_APP'));
set('repository', getenv('DEPLPOYER_REPO'));
set('php_fpm_version', '8.2');
set('cleanup_use_sudo', true);
set('keep_releases', 3);

add('shared_files', []);
add('shared_dirs', []);
add('writable_dirs', []);
add('crontab:jobs', [
    '* * * * * cd {{release_path}} && {{bin/php}} artisan schedule:run >> /dev/null 2>&1',
]);

// Hosts

host('prod')
    ->set('remote_user', getenv('DEPLOYER_USER'))
    ->set('hostname', getenv('DEPLOYER_HOSTNAME'))
    ->set('deploy_path', getenv('DEPLOYER_PATH'))
    ->set('bin/crontab', 'sudo /usr/bin/crontab')
    ->set('bin/php', 'sudo docker exec -d -t -w {{release_path}} swag php')
    ->set('bin/composer', 'sudo docker exec -t -w {{release_path}} swag /config/php/composer.phar');

host('dev')
    ->set('remote_user', getenv('DEPLOYER_USER'))
    ->set('branch', 'dev')
    ->set('hostname', getenv('DEPLOYER_HOSTNAME'))
    ->set('deploy_path', getenv('DEPLOYER_PATH'))
    ->set('bin/crontab', 'sudo /usr/bin/crontab')
    ->set('bin/php', 'sudo docker exec -d -t -w {{release_path}} swag php')
    ->set('bin/composer', 'sudo docker exec -t -w {{release_path}} swag /config/php/composer.phar');

task('deploy', [
    'version:prepare',
    'deploy:prepare',
    'composer:prepare',
    'deploy:vendors',
    'version:set',
    'artisan:horizon:terminate',
    'artisan:storage:link',
    'artisan:view:cache',
    'artisan:config:cache',
    'artisan:migrate',
    'npm:install',
    'npm:run:prod',
    'artisan:horizon',
    'deploy:publish',
]);

after('deploy:vendors', 'deploy:version:prepare');
task('deploy:version:prepare', function () {
    run('sudo docker exec -t -w /srv/web/sites/spark/.dep/repo/ swag git config --global --add safe.directory /srv/web/sites/spark/.dep/repo');
});

task('composer:prepare', function () {
    run('sudo docker exec -t -w {{release_path}} swag /config/php/composer.phar config http-basic.wire-elements-pro.composer.sh %secret%', secret: getenv('WIRE_SECRET'));
});

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
});

task('npm:run:prod', function () {
    run('sudo docker exec -t -w {{release_path}} swag npm run build');
});

after('deploy:failed', 'deploy:unlock');
after('deploy:success', 'crontab:sync');
