import { AppShell } from "@/components/AppShell";
import { RouteGuard } from "@/components/RouteGuard";

export default function DashboardLayout({ children }: LayoutProps<"/">) {
  return (
    <RouteGuard rule="ready">
      <AppShell>{children}</AppShell>
    </RouteGuard>
  );
}
