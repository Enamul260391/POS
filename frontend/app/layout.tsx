import type { Metadata } from "next";
import { Geist, Geist_Mono } from "next/font/google";

import { env } from "@/lib/env";

import "./globals.css";

const geistSans = Geist({
  variable: "--font-geist-sans",
  subsets: ["latin"],
});

const geistMono = Geist_Mono({
  variable: "--font-geist-mono",
  subsets: ["latin"],
});

export const metadata: Metadata = {
  title: {
    default: env.appName,
    template: `%s | ${env.appName}`,
  },
  description: "Point of Sale management system.",
};

export default function RootLayout({ children }: LayoutProps<"/">) {
  return (
    /* suppressHydrationWarning: browser extensions may add attributes
       (e.g. a style variable) to <html> before React hydrates — React would
       otherwise report an unfixable attribute mismatch for something the app
       never rendered. It only silences attribute checks on this element. */
    <html
      lang="en"
      className={`${geistSans.variable} ${geistMono.variable} h-full antialiased`}
      suppressHydrationWarning
    >
      <body className="min-h-full flex flex-col">{children}</body>
    </html>
  );
}
