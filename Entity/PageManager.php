<?php

namespace Symbio\OrangeGate\PageBundle\Entity;

use Doctrine\Common\Persistence\ManagerRegistry;
use Sonata\PageBundle\Entity\PageManager as BasePageManager;
use Sonata\PageBundle\Model\PageManagerInterface;
use Sonata\PageBundle\Model\PageInterface;
use Sonata\PageBundle\Model\SiteInterface;
use Sonata\PageBundle\Model\Page as ModelPage;

/**
 * This class manages PageInterface persistency with the Doctrine ORM
 */
class PageManager extends BasePageManager implements PageManagerInterface
{
    private $pages = array();

    /**
     * @param \Sonata\PageBundle\Model\PageInterface $page
     *
     * @return void
     */
    public function fixUrl(PageInterface $page)
    {
        if ($page->isInternal()) {
            $page->setUrl(null); // internal routes do not have any url ...

            return;
        }

        // hybrid page cannot be altered
        if (!$page->isHybrid()) {

            // make sure Page has a valid url
            if ($page->getParent()) {
                foreach ($page->getTranslations() as $trans) {
                    $locale = $trans->getLocale();

                    if (!$trans->getSlug()) {
                        $trans->setSlug(ModelPage::slugify($trans->getName()));
                    }

                    $parent = $page->getParent();
                    foreach ($parent->getTranslations() as $ptrans) {
                        if ($ptrans->getLocale() === $locale) {
                            $url = $ptrans->getUrl();
                            if ($url == '/') {
                                $base = '/';
                            } elseif (substr($url, -1) != '/') {
                                $base = $url . '/';
                            } else {
                                $base = $url;
                            }

                            $trans->setUrl($base . $trans->getSlug());
                        }
                    }
                }
            } else {
                foreach ($page->getTranslations() as $trans) {
                    // a parent page does not have any slug - can have a custom url ...
                    $trans->setUrl('/' . $trans->getSlug());
                }
            }
        }

        foreach ($page->getChildren() as $child) {
            $this->fixUrl($child);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function loadPages(SiteInterface $site)
    {
        $query = $this->getEntityManager()
            ->createQuery(sprintf('SELECT p FROM %s p INDEX BY p.id WHERE p.site = %d ORDER BY p.position ASC', $this->class, $site->getId()));

        $query->setHint(
            \Doctrine\ORM\Query::HINT_CUSTOM_OUTPUT_WALKER,
            'Gedmo\\Translatable\\Query\\TreeWalker\\TranslationWalker'
        );

        $query->setHint(
            \Gedmo\Translatable\TranslatableListener::HINT_TRANSLATABLE_LOCALE,
            $site->getLocale()
        );

        $pages = $query->execute();

        foreach ($pages as $page) {
            $parent = $page->getParent();

            $page->disableChildrenLazyLoading();
            if (!$parent) {
                continue;
            }

            $pages[$parent->getId()]->disableChildrenLazyLoading();
            //$pages[$parent->getId()]->addChildren($page);
        }

        return $pages;
    }

    public function findOneByUrl($site, $url)
    {
        $site_id = $site->getId();

        if (!isset($this->pages[$site_id])) {
            $this->pages[$site_id] = $this->loadPages($site);
        }

        foreach ($this->pages[$site_id] as $page) {
            if ($page->getUrl() === $url) {
                return $page;
            }
        }

        return null;
    }

    public function findOneById($site, $id)
    {
        if ($site instanceof SiteInterface) {
            if (!isset($this->pages[$site->getId()])) {
                $this->pages[$site->getId()] = $this->loadPages($site);
            }
            foreach ($this->pages[$site->getId()] as $page) {
                if ($page->getId() === (int)$id) {
                    return $page;
                }
            }
        } else {
            $page = $this->findOneBy(array('id' => $id));

            if ($page && !isset($this->pages[$page->getSite()->getId()])) {
                $this->pages[$page->getSite()->getId()] = $this->loadPages($page->getSite());
            }

            return $page;
        }
    }

    /**
     * @param Page $page
     * @return mixed
     */
    public function findBlockLinksTo($page)
    {
        return $this->getEntityManager()->createQuery(sprintf(
                "SELECT p FROM %s p JOIN p.blocks b JOIN b.translations t
                  WHERE t.settings LIKE '%%{{ url(%d) }}%%' OR
                    (t.settings LIKE '%%\"type\":\"internal\"%%' AND t.settings LIKE '%%\"internal_link_page_id\":%d%%')",
                $this->class,
                $page->getId(),
                $page->getId()
            ))
            ->execute()
        ;
    }

    /**
     * @param Page $page
     * @return mixed
     */
    public function findSnapshotLinksTo($page)
    {
        return $this->getEntityManager()->createQuery(sprintf(
                "SELECT p FROM %s p JOIN p.snapshots s WHERE s.content LIKE '%%{{ url(%d) }}%%'",
                $this->class,
                $page->getId()
            ))
            ->execute()
        ;
    }
}
