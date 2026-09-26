import { Link } from '@inertiajs/react';
import {
    Activity,
    BookOpen,
    Camera,
    ChartSpline,
    FolderGit2,
    KeyRound,
    LayoutDashboard,
    LayoutGrid,
    Library,
    Rocket,
    Server,
    Trophy,
    Users,
    Wallet,
} from 'lucide-react';
import AppLogo from '@/components/app-logo';
import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import { NavUser } from '@/components/nav-user';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { usePermissions } from '@/hooks/use-permissions';
import { usePlan } from '@/hooks/use-plan';
import { analytics, dashboard, leaderboard } from '@/routes';
import {
    activity as adminActivity,
    dashboard as adminDashboard,
    finance as adminFinance,
    system as adminSystem,
} from '@/routes/admin';
import { index as adminCourses } from '@/routes/admin/courses';
import { index as adminRoles } from '@/routes/admin/roles';
import { index as adminUsers } from '@/routes/admin/users';
import { index as coursesIndex } from '@/routes/courses';
import { index as photosIndex } from '@/routes/photos';
import type { NavItem } from '@/types';
import type { AdminPermissionValue } from '@/types/auth';

const mainNavItems: NavItem[] = [
    {
        title: 'Dashboard',
        href: dashboard(),
        icon: LayoutGrid,
    },
    {
        title: 'Courses',
        href: coursesIndex(),
        icon: Rocket,
    },
    {
        title: 'Photo Log',
        href: photosIndex(),
        icon: Camera,
    },
    {
        title: 'Leaderboard',
        href: leaderboard(),
        icon: Trophy,
    },
];

/**
 * Advanced analytics is sold on Pro, and the route turns away anyone else.
 *
 * Hidden rather than shown-and-locked because there is nothing to preview: a
 * course card behind a lock still sells the mission inside it, while a nav
 * item that only ever 403s sells nothing and teaches the pilot their sidebar
 * lies. The pricing page is where the tier is argued for.
 */
const analyticsNavItem: NavItem = {
    title: 'Analytics',
    href: analytics(),
    icon: ChartSpline,
};

/**
 * The admin area, one entry per section, each shown only to staff whose
 * permissions open it. Every entry also needs `access_admin` — the door every
 * admin route sits behind — so a role holding a section without it sees
 * nothing here, which is what the server would answer too.
 */
const adminNavItems: (NavItem & { permission: AdminPermissionValue })[] = [
    {
        title: 'Overview',
        href: adminDashboard(),
        icon: LayoutDashboard,
        permission: 'access_admin',
    },
    {
        title: 'Activity',
        href: adminActivity(),
        icon: Activity,
        permission: 'access_admin',
    },
    {
        title: 'Users',
        href: adminUsers(),
        icon: Users,
        permission: 'manage_users',
    },
    {
        title: 'Roles',
        href: adminRoles(),
        icon: KeyRound,
        permission: 'manage_roles',
    },
    {
        title: 'Course Admin',
        href: adminCourses(),
        icon: Library,
        permission: 'manage_courses',
    },
    {
        title: 'Finance',
        href: adminFinance(),
        icon: Wallet,
        permission: 'view_finance',
    },
    {
        title: 'System',
        href: adminSystem(),
        icon: Server,
        permission: 'view_system',
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

export function AppSidebar() {
    const { hasFeature } = usePlan();
    const { can } = usePermissions();

    const navItems = hasFeature('advanced_analytics')
        ? [...mainNavItems, analyticsNavItem]
        : mainNavItems;

    const adminItems = can('access_admin')
        ? adminNavItems.filter((item) => can(item.permission))
        : [];

    return (
        <Sidebar collapsible="icon" variant="sidebar">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href={dashboard()} prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>

            <SidebarContent>
                <NavMain items={navItems} />
                {adminItems.length > 0 && (
                    <NavMain items={adminItems} label="Administration" />
                )}
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
