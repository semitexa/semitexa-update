<?php

declare(strict_types=1);

use Semitexa\Core\Support\ProjectRoot;
use Semitexa\Dev\Console\Command\DeployBootstrapRemoteCommand;
use Symfony\Component\Console\Tester\CommandTester;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$target = getenv('REMOTE_SMOKE_TARGET');
$path = getenv('REMOTE_SMOKE_DEPLOY_PATH');

if (!is_string($target) || trim($target) === '') {
    fwrite(STDERR, "REMOTE_SMOKE_TARGET is required.\n");
    exit(1);
}

if (!is_string($path) || trim($path) === '') {
    fwrite(STDERR, "REMOTE_SMOKE_DEPLOY_PATH is required.\n");
    exit(1);
}

chdir(dirname(__DIR__, 3));
ProjectRoot::reset();

$command = new DeployBootstrapRemoteCommand();
$tester = new CommandTester($command);
$tester->setInputs(['DEPLOY NEW SERVER']);

$exitCode = $tester->execute([
    '--target' => $target,
    '--path' => $path,
    '--json' => true,
], [
    'interactive' => true,
]);

fwrite(STDOUT, $tester->getDisplay(true));
exit($exitCode);
