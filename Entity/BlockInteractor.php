<?php

namespace Symbio\OrangeGate\PageBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Bridge\Doctrine\RegistryInterface;

use Sonata\BlockBundle\Model\BlockManagerInterface;
use Sonata\PageBundle\Model\BlockInteractorInterface;
use Sonata\PageBundle\Model\PageInterface;

/**
 * This class interacts with blocks
 */
class BlockInteractor implements BlockInteractorInterface
{
    protected $pageBlocksLoaded = array();

    protected $registry;

    protected $blockManager;

    /**
     * Constructor
     *
     * @param RegistryInterface     $registry     Doctrine registry
     * @param BlockManagerInterface $blockManager Block manager
     */
    public function __construct(RegistryInterface $registry, BlockManagerInterface $blockManager)
    {
        $this->blockManager = $blockManager;
        $this->registry     = $registry;
    }

    /**
     * {@inheritdoc}
     */
    public function getBlock($id)
    {
        $blocks = $this->getEntityManager()->createQueryBuilder()
            ->select('b')
            ->from($this->blockManager->getClass(), 'b')
            ->where('b.id = :id')
            ->setParameters(array(
              'id' => $id
            ))
            ->getQuery()
            ->execute();

        return count($blocks) > 0 ? $blocks[0] : false;
    }

    /**
     * {@inheritdoc}
     */
    public function getBlocksById(PageInterface $page)
    {
        $query = $this->getEntityManager()
            ->createQuery(sprintf('SELECT b FROM %s b INDEX BY b.id WHERE b.page = :page ORDER BY b.position ASC', $this->blockManager->getClass()))
            ->setParameters(array(
                 'page' => $page->getId()
            ));

        $query->setHint(
            \Doctrine\ORM\Query::HINT_CUSTOM_OUTPUT_WALKER,
            'Gedmo\\Translatable\\Query\\TreeWalker\\TranslationWalker'
        );

        $query->setHint(
            \Gedmo\Translatable\TranslatableListener::HINT_TRANSLATABLE_LOCALE,
            $page->getSite()->getLocale()
        );

        $blocks = $query->execute();

        $page->setBlocks(new ArrayCollection($blocks));

        return $blocks;
    }

    /**
     * {@inheritdoc}
     */
    public function saveBlocksPosition(array $data = array(), $partial = true)
    {
        $em = $this->getEntityManager();
        $em->getConnection()->beginTransaction();

        try {
            foreach ($data as $block) {
                if (!$block['id'] or !array_key_exists('position', $block) or !$block['parent_id'] or !$block['page_id']) {
                    continue;
                }

                $this->blockManager->updatePosition($block['id'], $block['position'], $block['parent_id'], $block['page_id'], $partial);
            }

            $em->flush();
            $em->getConnection()->commit();
        } catch (\Exception $e) {
            $em->getConnection()->rollback();
            throw $e;
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function createNewContainer(array $values = array(), \Closure $alter = null)
    {
        $container = $this->blockManager->create();
        $container->setEnabled(isset($values['enabled']) ? $values['enabled'] : true);
        $container->setCreatedAt(new \DateTime);
        $container->setUpdatedAt(new \DateTime);
        $container->setType('sonata.page.block.container');

        if (isset($values['page'])) {
            $container->setPage($values['page']);
        }

        if (isset($values['name'])) {
            $container->setName($values['name']);
        } else {
            $container->setName(isset($values['code']) ? $values['code'] : 'No name defined');
        }

        $container->setSettings(array('code' => isset($values['code']) ? $values['code'] : 'no code defined'));
        $container->setPosition(isset($values['position']) ? $values['position'] : 1);

        if (isset($values['parent'])) {
            $container->setParent($values['parent']);
        }

        if ($page = $container->getPage()) {
            foreach ($page->getTranslations() as $locale => $trans) {
                $bt = new BlockTranslation();
                $bt->setLocale($locale);
                $bt->setObject($container);
                $bt->setEnabled(isset($values['enabled']) ? $values['enabled'] : true);
                $bt->setSettings($container->getSettings());
                $container->addTranslation($bt);
            }
        }

        if ($alter) {
            $alter($container);
        }

        $this->blockManager->save($container);

        return $container;
    }

    /**
     * {@inheritdoc}
     */
    public function loadPageBlocks(PageInterface $page)
    {
        if (isset($this->pageBlocksLoaded[$page->getId()])) {
            return array();
        }

        $blocks = $this->getBlocksById($page);

        $page->disableBlockLazyLoading();

        foreach ($blocks as $block) {
            $parent = $block->getParent();

            $block->disableChildrenLazyLoading();
            if (!$parent) {
                $page->addBlocks($block);

                continue;
            }

            $blocks[$block->getParent()->getId()]->disableChildrenLazyLoading();
            $blocks[$block->getParent()->getId()]->addChildren($block);
        }

        $this->pageBlocksLoaded[$page->getId()] = true;

        return $blocks;
    }

    /**
     * @return \Doctrine\ORM\EntityManager
     */
    private function getEntityManager()
    {
        return $this->registry->getManagerForClass($this->blockManager->getClass());
    }
}
