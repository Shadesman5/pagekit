<?php

declare(strict_types=1);

namespace Pagekit\Finder\Controller;

use Pagekit\Finder\Event\FileAccessEvent;
use Pagekit\Kernel\Exception\ForbiddenException;
use Pagekit\Routing\Attribute\Request;
use Pagekit\Routing\Attribute\Route;
use Symfony\Component\Finder\Finder;
use function Pagekit\__;

class FinderController
{
    public function __construct(
        private readonly mixed $request,
        private readonly mixed $url,
        private readonly mixed $file,
        private readonly mixed $path,
        private readonly mixed $module,
        private readonly mixed $events,
    ) {}

    public function indexAction(): array
    {
        $path = $this->request->get('path', '');
        
        if (!$dir = $this->getPath()) {
            return $this->error(__('Invalid path.'));
        }

        if (!is_dir($dir) || '-' === $mode = $this->getMode($dir)) {
            throw new ForbiddenException(__('Permission denied.'));
        }

        $data = array_fill_keys(['items'], []);
        $data['mode'] = $mode;

        $finder = Finder::create();

        $finder->sort(fn($a, $b) => $b->getRealpath() > $a->getRealpath() ? -1 : 1);

        foreach ($finder->depth(0)->in($dir) as $file) {

            if ('-' === $mode = $this->getMode($file->getPathname())) {
                continue;
            }

            $info = [
                'name'     => $file->getFilename(),
                'mime'     => 'application/'.($file->isDir() ? 'folder':'file'),
                'path'     => $this->normalizePath($path.'/'.$file->getFilename()),
                'url'      => ltrim($this->url->getStatic($file->getPathname(), [], 'base'), '/'),
                'writable' => $mode == 'w'
            ];

            if (!$file->isDir()) {
                $info = array_merge($info, [
                    'size'         => $this->formatFileSize($file->getSize()),
                    'lastmodified' => date(\DateTime::ATOM, $file->getMTime())
                ]);
            }

            $data['items'][] = $info;
        }

        return $data;
    }

    #[Route('/createfolder', methods: ['POST'])]
    #[Request([], csrf: true)]
    public function createFolderAction(): array
    {
        $name = $this->request->request->get('name', '');
        if (empty($name) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            $name = $json['name'] ?? '';
        }
        
        if (!$this->isValidFilename($name)) {
            return $this->error(__('Invalid file name.'));
        }

        if (!$path = $this->getPath($name)) {
            return $this->error(__('Invalid path.'));
        }

        if (file_exists($this->getPath($name))) {
            return $this->error(__('Folder already exists.'));
        }

        if ('w' !== $this->getMode(dirname($path))) {
            throw new ForbiddenException(__('Permission denied.'));
        }

        try {

            $this->file->makeDir($path);

            return $this->success(__('Directory created.'));

        } catch(\Exception $e) {

            return $this->error(__('Unable to create directory.'));
        }
    }

    #[Route('/rename', methods: ['POST'])]
    #[Request([], csrf: true)]
    public function renameAction(): array
    {
        $oldname = $this->request->request->get('oldname', '');
        $newname = $this->request->request->get('newname', '');
        
        if ((empty($oldname) || empty($newname)) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            $oldname = $json['oldname'] ?? $oldname;
            $newname = $json['newname'] ?? $newname;
        }
        
        if (!$this->isValidFilename($newname)) {
            return $this->error(__('Invalid file name.'));
        }

        if (!$source = $this->getPath($oldname) or !$target = $this->getPath($newname)) {
            return $this->error(__('Invalid path.'));
        }

        // No-op: user saved without changing the name
        if ($source === $target) {
            return $this->success(__('Renamed.'));
        }

        if ('w' !== $this->getMode($source) || file_exists($target) || 'w' !== $this->getMode(dirname($target))) {
            throw new ForbiddenException(__('Permission denied.'));
        }

        if (!rename($source, $target)) {
            return $this->error(__('Unable to rename.'));
        }

        return $this->success(__('Renamed.'));
    }

    #[Route('/removefiles', methods: ['POST'])]
    #[Request([], csrf: true)]
    public function removeFilesAction(): array
    {
        $names = $this->request->request->all()['names'] ?? [];
        if (empty($names) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            $names = $json['names'] ?? [];
        }
        
        foreach ($names as $name) {

            if (!$path = $this->getPath($name)) {
                return $this->error(__('Invalid path.'));
            }

            if ('w' !== $this->getMode($path)) {
                throw new ForbiddenException(__('Permission denied.'));
            }

            try {

                $this->file->delete($path);

            } catch (\Exception $e) {

                return $this->error(__('Unable to remove.'));
            }
        }

        return $this->success(__('Removed selected.'));
    }

    #[Route('/upload', methods: ['POST'])]
    #[Request([], csrf: true)]
    public function uploadAction(): array
    {
        try {

            if (!$path = $this->getPath()) {
                return $this->error(__('Invalid path.'));
            }

            if (!is_dir($path) || 'w' !== $mode = $this->getMode($path)) {
                throw new ForbiddenException(__('Permission denied.'));
            }

            $files = $this->request->files->get('files');

            if (!$files) {
                return $this->error(__('No files uploaded.'));
            }

            foreach ($files as $file) {

                if (!$file->isValid()) {
                    return $this->error(sprintf(__('Uploaded file invalid. (%s)'), $file->getErrorMessage()));
                }

                if (!$this->isValidFilename($file->getClientOriginalName())) {
                    return $this->error(__('Invalid file name.'));
                }

                $file->move($path, $file->getClientOriginalName());
            }

            return $this->success(__('Upload complete.'));

        } catch(\Exception $e) {

            return $this->error(__('Unable to upload.'));
        }
    }

    protected function getMode($path): string
    {
        $mode = $this->events->trigger(new FileAccessEvent('system.finder'))->mode($path);

        if ('w' == $mode && !is_writable($path)) {
            $mode = 'r';
        }

        if ('r' == $mode && !is_readable($path)) {
            $mode = '-';
        }

        return $mode;
    }

    protected function formatFileSize($size): string
    {
      if ($size == 0) {
          return __('n/a');
      }

      $sizes = [__('%d Bytes'), __('%d  KB'), __('%d  MB'), __('%d  GB'), __('%d TB'), __('%d PB'), __('%d EB'), __('%d ZB'), __('%d YB')];
      $size  = round($size/pow(1024, ($i = floor(log($size, 1024)))), 2);
      return sprintf($sizes[$i], $size);
    }

    protected function getPath($path = '')
    {
        $root = strtr($this->path, '\\', '/');
        $path = $this->normalizePath($root.'/'.$this->request->get('root').'/'.$this->request->get('path').'/'.$path);

        return 0 === strpos($path, $root) ? $path : false;
    }

    /**
     * Normalizes the given path
     */
    protected function normalizePath($path): string
    {
        $path   = str_replace(['\\', '//'], '/', $path);
        $prefix = preg_match('|^(?P<prefix>([a-zA-Z]+:)?//?)|', $path, $matches) ? $matches['prefix'] : '';
        $path   = substr($path, strlen($prefix));
        $parts  = array_filter(explode('/', $path), 'strlen');
        $tokens = [];

        foreach ($parts as $part) {
            if ('..' === $part) {
                array_pop($tokens);
            } elseif ('.' !== $part) {
                $tokens[] = $part;
            }
        }

        return $prefix . implode('/', $tokens);
    }

    protected function isValidFilename($name): bool
    {
        if (empty($name)) {
            return false;
        }

        $extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $allowed = $this->module->get('system/finder')->config['extensions'];
        if (!empty($extension) && !in_array($extension, explode(',', $allowed))) {
            return false;
        }

        if (defined('PHP_WINDOWS_VERSION_MAJOR')) {
            return !preg_match('#[\\/:"*?<>|]#', $name);
        }

        return false === strpos($name, '/');
    }

    protected function success($message): array {
        return compact('message');
    }

    protected function error($message): array {
        return ['error' => true, 'message' => $message];
    }
}
