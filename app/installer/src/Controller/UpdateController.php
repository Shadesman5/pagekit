<?php

declare(strict_types=1);

namespace Pagekit\Installer\Controller;

use Pagekit\Installer\SelfUpdater;
use Pagekit\Routing\Attribute\Request;
use Pagekit\User\Attribute\Access;
use Psr\Container\ContainerInterface;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

#[Access('system: software updates', admin: true)]
class UpdateController
{
    private readonly string $systemApi;
    private readonly string $tempPath;

    public function __construct(
        private readonly ContainerInterface $app,
        private readonly mixed $session,
        private readonly mixed $response,
        private readonly mixed $version,
        private readonly string $path,
    ) {
        $this->systemApi = $this->app->has('system.api')
            ? $this->app->get('system.api')
            : 'https://pagekit.com';
        $this->tempPath = $this->app->has('path.temp')
            ? $this->app->get('path.temp')
            : sys_get_temp_dir();
    }

    public function indexAction(): array
    {
        return [
            '$view' => [
                'title' => __('Update'),
                'name' => 'installer:views/update.php',
            ],
            '$data' => [
                'api' => $this->systemApi,
                'version' => $this->version,
                'channel' => 'stable',
            ],
        ];
    }

    #[Request(['url' => 'string'], csrf: true)]
    public function downloadAction($url): array
    {
        $file = tempnam($this->tempPath, 'update_');
        $this->session->set('system.update', $file);

        if (!file_put_contents($file, @fopen($url, 'r'))) {
            throw new HttpException(500, 'Download failed or path not writable.');
        }

        return [];
    }

    #[Request([], csrf: true)]
    public function updateAction()
    {
        if (!$file = $this->session->get('system.update')) {
            throw new BadRequestHttpException(__('You may not call this step directly.'));
        }
        $this->session->remove('system.update');

        return $this->response->stream(function () use ($file) {
            $output = new StreamOutput(fopen('php://output', 'w'));

            try {

                if (!file_exists($file) || !is_file($file)) {
                    throw new \RuntimeException('File does not exist.');
                }

                $updater = new SelfUpdater($this->path, $output);
                $updater->update($file);

            } catch (\Exception $e) {
                $output->writeln(sprintf("\n<error>%s</error>", $e->getMessage()));
                $output->write("status=error");
            }

        });
    }
}
