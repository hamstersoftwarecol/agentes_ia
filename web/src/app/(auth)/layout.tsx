import { RouteGuard } from "@/components/RouteGuard";

export default function AuthLayout({ children }: LayoutProps<"/">) {
  return <RouteGuard rule="guest">{children}</RouteGuard>;
}
