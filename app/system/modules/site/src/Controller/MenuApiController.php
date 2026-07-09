<?php

declare(strict_types=1);

namespace Pagekit\Site\Controller;

use function Pagekit\__;

use Pagekit\Config\Config;
use Pagekit\Config\ConfigManager;
use Pagekit\Filter\FilterManager;
use Pagekit\Kernel\Exception\ConflictException;
use Pagekit\Routing\Attribute\Route;
use Pagekit\Site\MenuManager;
use Pagekit\Site\Model\Menu;
use Pagekit\Site\Model\Node;
use Pagekit\System\Controller\ValidatesRequestTrait;
use Pagekit\User\Attribute\Access;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Validator\Validator\ValidatorInterface;

#[Access('site: manage site')]
class MenuApiController
{
    use ValidatesRequestTrait;

    private readonly Config $siteConfig;

    public function __construct(
        private readonly ConfigManager $config,
        private readonly MenuManager $menu,
        private readonly Request $request,
        private readonly FilterManager $filter,
        protected readonly ValidatorInterface $validator,
    ) {
        $this->siteConfig = ($this->config)('system/site') ?? new Config();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    #[Route('/', methods: ['GET'])]
    public function indexAction(): array
    {
        $menus = $this->menu->all();

        $menus['trash'] = ['id' => 'trash', 'label' => __('Trash'), 'fixed' => true, 'count' => 0];

        $trashCount = 0;
        foreach ($menus as &$menu) {
            $menu['count'] = Node::where(['menu' => $menu['id']])->count();
            if ($menu['id'] === 'trash') {
                $trashCount = $menu['count'];
            }
        }
        unset($menu);

        if (!$trashCount) {
            unset($menus['trash']);
        }

        return array_values($menus);
    }

    /**
     * @return array<string, mixed>
     */
    #[Route('/', methods: ['POST'])]
    public function saveAction(): array
    {
        $data = $this->request->request->all()['menu'] ?? [];
        if (empty($data) && $this->request->getContent()) {
            $json = json_decode($this->request->getContent(), true);
            $data = $json['menu'] ?? $json ?? [];
        }

        $oldId = isset($data['id']) ? (string) $data['id'] : null;
        $label = isset($data['label']) ? (string) $data['label'] : null;

        // The id is derived from the label (business logic); the Assert
        // constraints then guarantee a non-empty, well-formed slug.
        $slug = ($this->filter)($label, 'slugify');

        $menu = new Menu();
        $menu->label = $label;
        $menu->id = is_string($slug) && $slug !== '' ? $slug : null;

        $this->validateOrFail($menu);

        $id = (string) $menu->id;

        if ($id !== $oldId) {

            if ($this->siteConfig->has('menus.' . $id)) {
                throw new ConflictException(__('Duplicate Menu Id.'));
            }

            $this->siteConfig->remove('menus.' . $oldId);

            Node::where(['menu = :old'], ['old' => $oldId])->update(['menu' => $id]);
        }

        $this->siteConfig->merge(['menus' => [$id => ['id' => $id, 'label' => $menu->label]]]);

        if (isset($data['positions'])) {
            $this->menu->assign($id, (array) $data['positions']);
        }

        return ['message' => 'success', 'menu' => $data];
    }

    /**
     * @return array<string, string>
     */
    #[Route('/{id}', methods: ['DELETE'])]
    public function deleteAction(?string $id = null): array
    {
        if (!$id) {
            $attribute = $this->request->attributes->get('id') ?? $this->request->get('id');
            $id = is_string($attribute) ? $attribute : null;
        }

        $menu = new Menu();
        $menu->id = $id;

        $this->validateOrFail($menu, null, ['Delete']);

        $this->siteConfig->remove('menus.' . (string) $menu->id);
        Node::where(['menu = :id'], ['id' => $menu->id])->update(['menu' => 'trash', 'status' => 0]);

        return ['message' => 'success'];
    }
}
