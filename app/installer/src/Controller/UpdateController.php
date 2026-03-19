<?php

declare(strict_types=1);

namespace Pagekit\Installer\Controller;

use Pagekit\Application as App;
use Pagekit\Installer\SelfUpdater;
use Pagekit\Routing\Attribute\Request;
use Pagekit\User\Attribute\Access;
use Symfony\Component\Console\Output\StreamOutput;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

#[Access('system: software updates', admin: true)]
class UpdateController
{
    public function __construct(
        private readonly mixed $session,
        private readonly mixed $response,
        private readonly mixed $version,
        private readonly string $path,
    ) {}

    public function indexAction(): array
    {
        return [
            '$view' => [
                'title' => __('Update'),
                'name' => 'installer:views/update.php'
            ],
            '$data' => [
                'api' => App::getInstance() ? App::getInstance()->get('system.api') : 'https://pagekit.com', // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
                'version' => $this->version,
                'channel' => 'stable'
            ]
        ];
    }

    #[Request(['url' => 'string'], csrf: true)]
    public function downloadAction($url): array
    {
        $tempPath = App::getInstance() ? App::getInstance()->get('path.temp') : sys_get_temp_dir(); // TODO: TEMPORARY BRIDGE - To be removed in Step 2.0.1e
        $file = tempnam($tempPath, 'update_');
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
