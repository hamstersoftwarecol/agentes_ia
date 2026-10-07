import Link from "next/link";
import { Card, Logo } from "./ui";

export function AuthShell({
  title,
  subtitle,
  children,
  footer,
}: {
  title: string;
  subtitle?: string;
  children: React.ReactNode;
  footer?: React.ReactNode;
}) {
  return (
    <main className="flex flex-1 flex-col items-center justify-center px-4 py-10">
      <Link href="/" className="mb-8">
        <Logo className="text-lg" />
      </Link>
      <div className="w-full max-w-sm">
        <Card>
          <h1 className="text-xl font-semibold tracking-tight">{title}</h1>
          {subtitle && <p className="mt-1 text-sm text-muted">{subtitle}</p>}
          <div className="mt-6">{children}</div>
        </Card>
        {footer && <p className="mt-4 text-center text-sm text-muted">{footer}</p>}
      </div>
    </main>
  );
}
