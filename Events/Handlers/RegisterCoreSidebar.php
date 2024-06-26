<?php

namespace Modules\Core\Events\Handlers;

use Maatwebsite\Sidebar\Group;
use Maatwebsite\Sidebar\Menu;
use Modules\Core\Sidebar\AbstractAdminSidebar;

class RegisterCoreSidebar extends AbstractAdminSidebar
{
    /**
     * Method used to define your sidebar menu groups and items
     */
    public function extendWith(Menu $menu): Menu
    {
        $menu->group(trans('core::sidebar.content'), function (Group $group) {
            $group->weight(50);
            $group->authorize(
                $this->auth->hasAccess('core.sidebar.group')
            );
        });

        return $menu;
    }
}
