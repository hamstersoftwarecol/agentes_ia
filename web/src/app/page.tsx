import Link from "next/link";
import { PricingSection } from "@/components/PricingSection";
import { Logo } from "@/components/ui";

const FEATURES = [
  {
    title: "Responde con tu información",
    text: "Carga tus servicios, precios y preguntas frecuentes. El agente solo responde con lo que tú le enseñas.",
  },
  {
    title: "Captura prospectos",
    text: "Detecta la intención de compra y pide nombre, teléfono y correo para que tu equipo dé seguimiento.",
  },
  {
    title: "Tú tomas el control",
    text: "Cuando hace falta una persona, la conversación pasa a tu bandeja sin que el cliente tenga que repetir nada.",
  },
];

export default function Home() {
  return (
    <>
      <header className="mx-auto flex w-full max-w-5xl items-center justify-between px-6 py-5">
        <Logo />
        <nav className="flex items-center gap-4 text-sm">
          <Link href="/login" className="hover:underline">
            Iniciar sesión
          </Link>
          <Link
            href="/register"
            className="rounded-lg bg-brand px-4 py-2 font-medium text-white hover:bg-brand-hover dark:text-stone-950"
          >
            Empezar gratis
          </Link>
        </nav>
      </header>

      <main className="mx-auto w-full max-w-5xl flex-1 px-6">
        <section className="py-20 text-center md:py-28">
          <h1 className="mx-auto max-w-3xl text-4xl font-semibold tracking-tight md:text-6xl">
            Un asistente de IA que atiende a tus clientes las 24 horas
          </h1>
          <p className="mx-auto mt-6 max-w-2xl text-lg text-muted">
            Crea tu agente, enséñale tu negocio e instálalo en tu sitio web con una línea de código.
          </p>
          <Link
            href="/register"
            className="mt-10 inline-block rounded-lg bg-brand px-6 py-3 font-medium text-white hover:bg-brand-hover dark:text-stone-950"
          >
            Crear mi agente
          </Link>
        </section>

        <section className="grid gap-4 pb-24 md:grid-cols-3">
          {FEATURES.map((feature) => (
            <div key={feature.title} className="rounded-xl border border-border bg-card p-6">
              <h2 className="font-semibold">{feature.title}</h2>
              <p className="mt-2 text-sm text-muted">{feature.text}</p>
            </div>
          ))}
        </section>

        <PricingSection />
      </main>

      <footer className="border-t border-border py-6 text-center text-sm text-muted">
        Hamster Software
      </footer>
    </>
  );
}
