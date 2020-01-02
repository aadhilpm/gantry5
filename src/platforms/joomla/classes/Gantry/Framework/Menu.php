<?php

/**
 * @package   Gantry5
 * @author    RocketTheme http://www.rockettheme.com
 * @copyright Copyright (C) 2007 - 2020 RocketTheme, LLC
 * @license   GNU/GPLv2 and later
 *
 * http://www.gnu.org/licenses/gpl-2.0.html
 */

namespace Gantry\Framework;

use Gantry\Component\Config\Config;
use Gantry\Component\Gantry\GantryTrait;
use Gantry\Component\Menu\AbstractMenu;
use Gantry\Component\Menu\Item;
use Gantry\Joomla\MenuHelper;
use Joomla\CMS\Application\CMSApplication;
use Joomla\CMS\Factory;
use Joomla\CMS\Language\Multilanguage;
use Joomla\CMS\Router\Route;

/**
 * Class Menu
 * @package Gantry\Framework
 */
class Menu extends AbstractMenu
{
    use GantryTrait;

    /**
     * @var CMSApplication
     */
    protected $application;

    /**
     * @var \JMenu
     */
    protected $menu;

    public function __construct()
    {
        $this->application = CMSApplication::getInstance('site');

        if (Multilanguage::isEnabled()) {
            /** @var CMSApplication $app */
            $app = Factory::getApplication();
            $language = $app->getLanguage();
            $tag = $language->getTag();
        } else {
            $tag = '*';
        }

        $this->menu = $this->application->getMenu();
        $this->default = $this->menu->getDefault($tag);
        $this->active  = $this->menu->getActive();
    }

    /**
     * @param array $params
     */
    public function init(&$params)
    {
        parent::init($params);

        if (!empty($params['admin'])) {
            $menuType = MenuHelper::getMenuType($params['menu']);

            $config = $this->config();
            $config->set('settings.title', $menuType->title);
            $config->set('settings.description', $menuType->description);
        }
    }

    /**
     * Return list of menus.
     *
     * @return array
     * @throws \RuntimeException
     */
    public function getMenus()
    {
        static $items;

        if ($items === null) {
            // Works also in Joomla 4
            require_once JPATH_ADMINISTRATOR . '/components/com_menus/helpers/menus.php';

            $items = (array)\MenusHelper::getMenuTypes();
        }

        return $items;
    }

    /**
     * @return array
     */
    public function getGroupedItems()
    {
        $groups = [];

        // Works also in Joomla 4
        require_once JPATH_ADMINISTRATOR . '/components/com_menus/helpers/menus.php';

        // Get the menu items.
        $items = \MenusHelper::getMenuLinks();

        // Build the groups arrays.
        foreach ($items as $item) {
            // Initialize the group.
            $groups[$item->menutype] = [];

            // Build the options array.
            foreach ($item->links as $link) {
                $groups[$item->menutype][$link->value] = [
                    'spacing' => str_repeat('&nbsp; ', max(0, $link->level-1)),
                    'label' => $link->text
                ];
            }
        }

        return $groups;
    }

    /**
     * Return default menu.
     *
     * @return string|null
     */
    public function getDefaultMenuName()
    {
        return $this->default ? $this->default->menutype : null;
    }

    /**
     * Returns true if the platform implements a Default menu.
     *
     * @return bool
     */
    public function hasDefaultMenu()
    {
        return true;
    }

    /**
     * Return active menu.
     *
     * @return string|null
     */
    public function getActiveMenuName()
    {
        return $this->active ? $this->active->menutype : null;
    }

    /**
     * Returns true if the platform implements an Active menu.
     *
     * @return boolean
     */
    public function hasActiveMenu()
    {
        return true;
    }

    /**
     * @return string|null
     */
    public function getCacheId()
    {
        $application = Factory::getApplication();
        $user = $application->getIdentity();

        if ($user && !$user->guest) {
            return null;
        }

        return $this->active ? $this->active->id : 0;
    }

    /**
     * @param object $item TODO: which object?
     * @return bool
     */
    public function isActive($item)
    {
        $tree = $this->base->tree;

        if (\in_array($item->id, $tree, true)) {
            return true;
        }

        if ($item->type === 'alias') {
            $aliasToId = $item->link_id;

            if (\count($tree) > 0 && $aliasToId === $tree[\count($tree) - 1]) {
                return (bool) $this->params['highlightAlias'];
            }

            if (\in_array($aliasToId, $tree, true)) {
                return (bool) $this->params['highlightParentAlias'];
            }
        }

        return false;
    }

    /**
     * @param object $item TODO: which object?
     * @return bool
     */
    public function isCurrent($item)
    {
        return $item->id == $this->active->id
        || ($item->type === 'alias' && $item->params->get('aliasoptions') == $this->active->id);
    }

    /**
     * Get menu items from the platform.
     *
     * @param array $params
     * @return array    List of routes to the pages.
     */
    protected function getItemsFromPlatform($params)
    {
        $attributes = ['menutype'];
        $values = [$params['menu']];

        // Items are already filtered by access and language, in admin we need to work around that.
        if (Factory::getApplication()->isClient('administrator')) {
            $attributes[] = 'access';
            $values[] = null;

            $attributes[] = 'language';
            $values[] = null;
        }

        return $this->menu->getItems($attributes, $values);
    }

    /**
     * Get base menu item.
     *
     * If itemid is not specified or does not exist, return active menu item.
     * If there is no active menu item, fall back to home page for the current language.
     * If there is no home page, return null.
     *
     * @param   int  $itemid
     *
     * @return  object|null
     */
    protected function calcBase($itemid = null)
    {
        $menu = $this->application->getMenu();

        // Get base menu item.
        $base = $itemid ? $menu->getItem($itemid) : null;

        if (!$base) {
            // Use active menu item or fall back to default menu item.
            $base = $this->active ?: $this->default;
        }

        // Return base menu item.
        return $base;
    }

    /**
     * Get a list of the menu items.
     *
     * Logic was originally copied from Joomla 3.4 mod_menu/helper.php (joomla-cms/staging, 2014-11-12).
     * We should keep the contents of the function similar to Joomla in order to review it against any changes.
     *
     * @param  array  $params
     * @param  array  $items
     */
    public function getList(array $params, array $items)
    {
        // Get base menu item for this menu (defaults to active menu item).
        $this->base = $this->calcBase($params['base']);

        // Make sure that the menu item exists.
        if (!$this->base && !$this->application->isClient('administrator')) {
            return;
        }

        // FIXME: need to create collection class to gather the sibling data, otherwise caching cannot work.
        // $application = Factory::getApplication();
        //$user = $application->getIdentity();
        //$levels = $user ? $user->getAuthorisedViewLevels() : [];
        //asort($levels);
        //$key = 'gantry_menu_items.' . json_encode($params) . '.' . json_encode($levels) . '.' . $this->base->id;
        //$cache = Factory::getCache('mod_menu', '');
        //try {
        //    $this->items = $cache->get($key);
        //} catch (\Exception $e) {
        //    $this->items = false;
        //}

        if (1) {
            $tree    = isset($this->base->tree) ? $this->base->tree : [];
            $start   = $params['startLevel'];
            $max     = $params['maxLevels'];
            $end     = $max ? $start + $max - 1 : 0;

            $menuItems = $this->getItemsFromPlatform($params);

            $itemMap = [];
            foreach ($items as $path => &$itemRef) {
                if (isset($itemRef['id']) && is_numeric($itemRef['id'])) {
                    $itemRef['path'] = $path;
                    $itemMap[$itemRef['id']] = &$itemRef;
                }
            }
            unset($itemRef);

            foreach ($menuItems as $menuItem) {
                if (($start && $start > $menuItem->level)
                    || ($end && $menuItem->level > $end)
                    || ($start > 1 && !\in_array($menuItem->tree[$start - 2], $tree, true))) {
                    continue;
                }

                // These params always come from Joomla and cannot be overridden.
                $itemParams = [
                    'id' => $menuItem->id,
                    'type' => $menuItem->type,
                    'alias' => $menuItem->alias,
                    'path' => $menuItem->route,
                    'link' => $menuItem->link,
                    'link_title' => $menuItem->params->get('menu-anchor_title', ''),
                    'rel' => $menuItem->params->get('menu-anchor_rel', ''),
                    'enabled' => (bool) $menuItem->params->get('menu_show', 1),
                ];

                // Rest of the items will come from saved configuration.
                if (isset($itemMap[$menuItem->id])) {
                    // ID found, use it.
                    $itemParams += $itemMap[$menuItem->id];

                    // Store new path for the menu item into path map.
                    if ($itemParams['path'] !== $itemMap[$menuItem->id]['path']) {
                        if (!$this->pathMap) {
                            $this->pathMap = new Config([]);
                        }
                        $this->pathMap->set(preg_replace('|/|u', '/children/', $itemMap[$menuItem->id]['path']) . '/path', $itemParams['path'], '/');
                    }
                } elseif (isset($items[$menuItem->route])) {
                    // ID not found, try to use route.
                    $itemParams += $items[$menuItem->route];
                }

                // Get default target from Joomla.
                switch ($menuItem->browserNav)
                {
                    default:
                    case 0:
                        // Target window: Parent.
                        $target = '_self';
                        break;
                    case 1:
                    case 2:
                        // Target window: New with navigation.
                        $target = '_blank';
                        break;
                }

                // And if not available in configuration, default to Joomla.
                $itemParams += [
                    'title' => $menuItem->title,
                    'anchor_class' => $menuItem->params->get('menu-anchor_css', ''),
                    'image' => $menuItem->params->get('menu_image', ''),
                    'icon_only' => !$menuItem->params->get('menu_text', 1),
                    'target' => $target
                ];

                $item = new Item($this, $menuItem->route, $itemParams);
                $this->add($item);

                $link  = $item->link;

                switch ($item->type) {
                    case 'separator':
                    case 'heading':
                        // These types have no link.
                        $link = null;
                        break;

                    case 'url':
                        if ((strpos($item->link, 'index.php?') === 0) && (strpos($item->link, 'Itemid=') === false)) {
                            // If this is an internal Joomla link, ensure the Itemid is set.
                            $link = $item->link . '&Itemid=' . $item->id;
                        }
                        break;

                    case 'alias':
                        // If this is an alias use the item id stored in the parameters to make the link.
                        $link = 'index.php?Itemid=' . $menuItem->params->get('aliasoptions', 0);

                        // FIXME: Joomla 4: missing multilanguage support
                        break;

                    default:
                        $application = $this->application;
                        $router = $application::getRouter();

                        // FIXME: Joomla 4: do we need anything else?
                        if (version_compare(JVERSION, 4, '<') && $router->getMode() !== JROUTER_MODE_SEF) {
                            $link .= '&Itemid=' . $item->id;
                        } else {
                            $link = 'index.php?Itemid=' . $item->id;

                            if (isset($menuItem->query['format']) && $application->get('sef_suffix')) {
                                $link .= '&format=' . $menuItem->query['format'];
                            }
                        }
                        break;
                }

                if (!$link) {
                    $item->url(false);
                } elseif (strcasecmp(substr($link, 0, 4), 'http') && (strpos($link, 'index.php?') !== false)) {
                    $item->url(Route::_($link, false, $menuItem->params->get('secure')));
                } else {
                    $item->url(Route::_($link, false));
                }

                if ($item->type === 'url') {
                    // Moved from modules/mod_menu/tmpl/default_url.php, not sure why Joomla had application logic in there.
                    // Keep compatibility to Joomla menu module, but we need non-encoded version of the url.
                    $item->url(
                        htmlspecialchars_decode(\JFilterOutput::ampReplace(htmlspecialchars($item->link, ENT_COMPAT|ENT_SUBSTITUTE, 'UTF-8')))
                    );
                }
            }

            // FIXME: need to create collection class to gather the sibling data, otherwise caching cannot work.
            // $cache->store($this->items, $key);
        }
    }
}
