import { Link } from '@inertiajs/react';
import {
    BookOpen,
    Camera,
    ChartSpline,
    FolderGit2,
    LayoutGrid,
    Rocket,
    Trophy,
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
import { usePlan } from '@/hooks/use-plan';
import { analytics, dashboard, leaderboard } from '@/routes';
import { index as coursesIndex } from '@/routes/courses';
import { index as photosIndex } from '@/routes/photos';
import type { NavItem } from '@/types';

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

    const navItems = hasFeature('advanced_analytics')
        ? [...mainNavItems, analyticsNavItem]
        : mainNavItems;

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
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
                <NavUser />
            </SidebarFooter>
        </Sidebar>
    );
}
