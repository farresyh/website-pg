"use client";

/**
 * PRD §16 item 49 — step-by-step custom-domain SOP, EN + BM.
 *
 * The main domain (yourbrand.com) is the primary path, since that is what
 * affiliates actually buy a domain for (ADR-060 2026-10-02 addendum).
 * Provider-opaque (ADR-060): never names the platform's own host. Naming the
 * affiliate's own DNS provider (Cloudflare, GoDaddy) is fine. Screenshots are
 * a supplement: every step must still make sense if a provider redesigns its UI.
 */

import { useState } from "react";
import Image, { type StaticImageData } from "next/image";
import cfAddRecord from "./guide-images/cloudflare-1-add-record.png";
import cfWrongProxied from "./guide-images/cloudflare-2-wrong-proxied.png";
import cfCorrectDnsOnly from "./guide-images/cloudflare-3-correct-dns-only.png";
import gdRecords from "./guide-images/godaddy-records-parked-www.png";
import gdEditA from "./guide-images/godaddy-edit-a-record.png";
import gdManagedElsewhere from "./guide-images/godaddy-dns-managed-elsewhere.png";

type Lang = "en" | "ms";
type Row = { address: string; record: string };

const T = {
  en: {
    steps: [
      {
        title: "Add your domain below",
        body: "Usually your main domain, e.g. yourbrand.com. Want www.yourbrand.com to work too? Add it as a second domain. Type the full address into “Add a domain” below.",
      },
      {
        title: "Find out where your DNS is managed",
        body: "The company you bought the domain from is not always the one that manages its DNS. In GoDaddy, open your domain → DNS. If it says “DNS Provider: Cloudflare” (or another name), make the change at that provider instead.",
      },
      {
        title: "Add the record from the table below",
        body: "Which record depends on the address you added and on your DNS provider. Copy the values exactly.",
      },
      {
        title: "Add any ownership record shown",
        body: "If your domain row below says “Also add this record to confirm ownership”, add that record too, the same way (usually Type = TXT).",
      },
      {
        title: "Click “Check now”",
        body: "DNS changes usually take a few minutes, sometimes up to a few hours. Click “Check now” on your domain until it shows active.",
      },
    ],
    target: "CNAME target",
    copy: "Copy",
    copied: "Copied",
    table: {
      title: "Which record to add",
      address: "Address you added",
      record: "Record at your DNS provider",
      rows: (cname: string, ip: string): Row[] => [
        { address: "yourbrand.com (main domain) on Cloudflare", record: `CNAME · Name @ · Target ${cname} · DNS only` },
        { address: "yourbrand.com (main domain) on GoDaddy and most others", record: `A · Name @ · Value ${ip}` },
        { address: "www.yourbrand.com", record: `CNAME · Name www · Target ${cname}` },
        { address: "shop.yourbrand.com (any other prefix)", record: `CNAME · Name shop · Target ${cname}` },
      ],
    },
    tipsTitle: "Tips by provider",
    cloudflare: {
      name: "Cloudflare",
      steps: [
        "Open your domain → DNS → Records, then click “Add record”.",
        "Type: CNAME. Name: @ for the main domain (Cloudflare allows this), or www / shop. Target: the CNAME target above.",
        "Turn Proxy status OFF so it shows “DNS only” (grey cloud). An orange cloud (“Proxied”) stops your storefront’s security certificate from working.",
        "Click Save. If a record with the same name already exists, edit that one instead.",
      ],
      example: "The screenshots use the prefix shop as an example. For your main domain, type @ in Name.",
      wrong: "Wrong: orange cloud, “Proxied”",
      right: "Right: grey cloud, “DNS only”",
    },
    godaddy: {
      name: "GoDaddy",
      steps: [
        "Open your domain → DNS. If you see “DNS Provider: …” with another company’s name, your DNS is managed there. Follow that provider’s steps instead.",
        "Main domain: click Edit on the existing A record named @ (its value says “Parked”) and replace the value with the IP address from the table. Save.",
        "www: click Edit on the existing CNAME record named www and replace its value with the CNAME target. Save.",
        "Open the Forwarding tab and make sure Domain Forwarding is off, or it will override these records.",
      ],
      recordsCaption: "A new GoDaddy domain already has these two rows. Edit them, don’t add duplicates.",
      editCaption: "Editing the A record @: Value changed from “Parked” to the IP address, then Save.",
      elsewhereCaption: "This domain’s DNS is managed at Cloudflare, so the record goes there.",
    },
    other: {
      name: "Other providers",
      steps: [
        "Main domain: if the provider supports ALIAS, ANAME or CNAME on @, point @ at the CNAME target. Otherwise add an A record for @ with the IP address from the table.",
        "In the Host/Name field type only @ or the prefix. Most providers add your domain automatically, so typing the full name creates yourbrand.com.yourbrand.com.",
        "Delete or edit any existing record with the same name, and turn off any proxy, CDN or “protection” switch on the record.",
      ],
    },
  },
  ms: {
    steps: [
      {
        title: "Tambah domain anda di bawah",
        body: "Biasanya domain utama anda, cth. jenamaanda.com. Mahu www.jenamaanda.com berfungsi juga? Tambah ia sebagai domain kedua. Taip alamat penuh dalam “Add a domain” di bawah.",
      },
      {
        title: "Kenal pasti di mana DNS anda diurus",
        body: "Syarikat tempat anda beli domain tidak semestinya yang mengurus DNS-nya. Dalam GoDaddy, buka domain anda → DNS. Jika tertulis “DNS Provider: Cloudflare” (atau nama lain), buat perubahan di provider tersebut.",
      },
      {
        title: "Tambah rekod mengikut jadual di bawah",
        body: "Rekod yang perlu bergantung pada alamat yang anda tambah dan provider DNS anda. Salin nilainya dengan tepat.",
      },
      {
        title: "Tambah rekod pengesahan jika ada",
        body: "Jika baris domain anda di bawah tertulis “Also add this record to confirm ownership”, tambah rekod itu juga dengan cara yang sama (biasanya Type = TXT).",
      },
      {
        title: "Klik “Check now”",
        body: "Perubahan DNS biasanya ambil beberapa minit, kadang-kadang sehingga beberapa jam. Klik “Check now” pada domain anda sehingga status jadi active.",
      },
    ],
    target: "Sasaran CNAME",
    copy: "Salin",
    copied: "Disalin",
    table: {
      title: "Rekod mana yang perlu ditambah",
      address: "Alamat yang anda tambah",
      record: "Rekod di provider DNS anda",
      rows: (cname: string, ip: string): Row[] => [
        { address: "jenamaanda.com (domain utama) di Cloudflare", record: `CNAME · Name @ · Target ${cname} · DNS only` },
        { address: "jenamaanda.com (domain utama) di GoDaddy dan kebanyakan provider lain", record: `A · Name @ · Value ${ip}` },
        { address: "www.jenamaanda.com", record: `CNAME · Name www · Target ${cname}` },
        { address: "shop.jenamaanda.com (awalan lain)", record: `CNAME · Name shop · Target ${cname}` },
      ],
    },
    tipsTitle: "Panduan mengikut provider",
    cloudflare: {
      name: "Cloudflare",
      steps: [
        "Buka domain anda → DNS → Records, kemudian klik “Add record”.",
        "Type: CNAME. Name: @ untuk domain utama (Cloudflare membenarkannya), atau www / shop. Target: sasaran CNAME di atas.",
        "MATIKAN Proxy status supaya tertulis “DNS only” (awan kelabu). Awan oren (“Proxied”) akan menyebabkan sijil keselamatan kedai anda gagal.",
        "Klik Save. Jika rekod dengan nama yang sama sudah wujud, edit rekod itu.",
      ],
      example: "Gambar ini guna awalan shop sebagai contoh. Untuk domain utama, taip @ dalam Name.",
      wrong: "Salah: awan oren, “Proxied”",
      right: "Betul: awan kelabu, “DNS only”",
    },
    godaddy: {
      name: "GoDaddy",
      steps: [
        "Buka domain anda → DNS. Jika ada “DNS Provider: …” dengan nama syarikat lain, DNS anda diurus di sana. Ikut langkah provider tersebut.",
        "Domain utama: klik Edit pada rekod A sedia ada bernama @ (nilainya “Parked”) dan tukar nilainya kepada alamat IP dalam jadual. Save.",
        "www: klik Edit pada rekod CNAME sedia ada bernama www dan tukar nilainya kepada sasaran CNAME. Save.",
        "Buka tab Forwarding dan pastikan Domain Forwarding dimatikan, jika tidak ia akan mengatasi rekod ini.",
      ],
      recordsCaption: "Domain GoDaddy baru sudah ada dua baris ini. Edit baris tersebut, jangan tambah pendua.",
      editCaption: "Edit rekod A @: tukar Value dari “Parked” kepada alamat IP, kemudian Save.",
      elsewhereCaption: "DNS domain ini diurus di Cloudflare, jadi rekod perlu ditambah di sana.",
    },
    other: {
      name: "Provider lain",
      steps: [
        "Domain utama: jika provider menyokong ALIAS, ANAME atau CNAME pada @, halakan @ ke sasaran CNAME. Jika tidak, tambah rekod A untuk @ dengan alamat IP dalam jadual.",
        "Dalam ruangan Host/Name, taip @ atau awalan sahaja. Kebanyakan provider tambah domain anda secara automatik, jadi menaip nama penuh akan jadi jenamaanda.com.jenamaanda.com.",
        "Padam atau edit rekod sedia ada dengan nama yang sama, dan matikan sebarang suis proxy, CDN atau “protection” pada rekod.",
      ],
    },
  },
};

function Shot({ src, alt, caption, tone, narrow }: { src: StaticImageData; alt: string; caption?: string; tone?: "good" | "bad"; narrow?: boolean }) {
  const ring = tone === "bad" ? "ring-2 ring-error-500" : tone === "good" ? "ring-2 ring-success-500" : "ring-1 ring-gray-200 dark:ring-gray-700";
  return (
    <figure className={`space-y-1.5 ${narrow ? "max-w-xl" : ""}`}>
      <Image src={src} alt={alt} className={`h-auto w-full rounded-lg ${ring}`} sizes="(min-width: 1024px) 640px, 100vw" />
      {caption && (
        <figcaption className={`text-theme-xs font-medium ${tone === "bad" ? "text-error-600 dark:text-error-500" : tone === "good" ? "text-success-600 dark:text-success-500" : "text-gray-500 dark:text-gray-400"}`}>
          {caption}
        </figcaption>
      )}
    </figure>
  );
}

function Bullets({ items }: { items: string[] }) {
  return (
    <ol className="list-decimal space-y-1.5 pl-5">
      {items.map((s) => (
        <li key={s}>{s}</li>
      ))}
    </ol>
  );
}

export function DomainSetupGuide({ cnameTarget, apexARecord }: { cnameTarget: string; apexARecord: string }) {
  const [lang, setLang] = useState<Lang>("en");
  const [copied, setCopied] = useState(false);
  const t = T[lang];

  return (
    <div className="space-y-5 p-5 text-theme-sm text-gray-600 dark:text-gray-300">
      <div className="flex justify-end gap-1">
        {(["en", "ms"] as const).map((l) => (
          <button
            key={l}
            type="button"
            onClick={() => setLang(l)}
            className={`rounded-md px-2.5 py-1 text-theme-xs font-medium ${
              lang === l ? "bg-brand-500 text-white" : "border border-gray-200 text-gray-600 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
            }`}
          >
            {l === "en" ? "English" : "Bahasa Melayu"}
          </button>
        ))}
      </div>

      <div>
        <p className="mb-1.5 text-theme-xs font-medium text-gray-500 dark:text-gray-400">{t.target}</p>
        <div className="flex items-center gap-3">
          <code className="rounded-md bg-gray-100 px-3 py-2 text-gray-800 dark:bg-gray-900 dark:text-white/90">{cnameTarget}</code>
          <button
            type="button"
            onClick={() => {
              navigator.clipboard.writeText(cnameTarget);
              setCopied(true);
              setTimeout(() => setCopied(false), 1500);
            }}
            className="rounded-lg border border-gray-200 px-3 py-1.5 text-theme-xs text-gray-600 hover:bg-gray-100 dark:border-gray-700 dark:text-gray-300 dark:hover:bg-white/5"
          >
            {copied ? t.copied : t.copy}
          </button>
        </div>
      </div>

      <ol className="space-y-3">
        {t.steps.map((s, i) => (
          <li key={s.title} className="flex gap-3">
            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-brand-50 text-theme-xs font-semibold text-brand-600 dark:bg-brand-500/15 dark:text-brand-400">
              {i + 1}
            </span>
            <div>
              <p className="font-medium text-gray-800 dark:text-white/90">{s.title}</p>
              <p className="mt-0.5">{s.body}</p>
            </div>
          </li>
        ))}
      </ol>

      <div>
        <p className="mb-2 font-medium text-gray-800 dark:text-white/90">{t.table.title}</p>
        <div className="overflow-x-auto rounded-lg border border-gray-200 dark:border-gray-800">
          <table className="min-w-full text-theme-xs">
            <thead className="bg-gray-50 text-left text-gray-500 dark:bg-white/5 dark:text-gray-400">
              <tr>
                <th className="px-3 py-2 font-medium">{t.table.address}</th>
                <th className="px-3 py-2 font-medium">{t.table.record}</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {t.table.rows(cnameTarget, apexARecord).map((r) => (
                <tr key={r.address}>
                  <td className="px-3 py-2 text-gray-700 dark:text-gray-300">{r.address}</td>
                  <td className="px-3 py-2 font-mono text-gray-800 dark:text-white/90">{r.record}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>

      <div className="space-y-2">
        <p className="font-medium text-gray-800 dark:text-white/90">{t.tipsTitle}</p>

        <details className="rounded-lg border border-gray-200 dark:border-gray-800">
          <summary className="cursor-pointer px-4 py-2.5 font-medium text-gray-700 dark:text-gray-200">{t.cloudflare.name}</summary>
          <div className="space-y-4 px-4 pb-4">
            <Bullets items={t.cloudflare.steps} />
            <Shot src={cfAddRecord} alt="Cloudflare DNS records toolbar with the Add record button" />
            <p className="text-theme-xs text-gray-500 dark:text-gray-400">{t.cloudflare.example}</p>
            <div className="grid gap-4 md:grid-cols-2">
              <Shot src={cfWrongProxied} alt="Cloudflare Add record form with Proxy status set to Proxied" caption={t.cloudflare.wrong} tone="bad" />
              <Shot src={cfCorrectDnsOnly} alt="Cloudflare Add record form with Proxy status set to DNS only" caption={t.cloudflare.right} tone="good" />
            </div>
          </div>
        </details>

        <details className="rounded-lg border border-gray-200 dark:border-gray-800">
          <summary className="cursor-pointer px-4 py-2.5 font-medium text-gray-700 dark:text-gray-200">{t.godaddy.name}</summary>
          <div className="space-y-4 px-4 pb-4">
            <Bullets items={t.godaddy.steps} />
            <Shot src={gdRecords} alt="GoDaddy DNS records with an A record @ Parked and a CNAME record www" caption={t.godaddy.recordsCaption} narrow />
            <Shot src={gdEditA} alt="GoDaddy edit form for the A record @ with value 76.76.21.21" caption={t.godaddy.editCaption} narrow />
            <Shot src={gdManagedElsewhere} alt="GoDaddy DNS page showing DNS Provider: Cloudflare" caption={t.godaddy.elsewhereCaption} narrow />
          </div>
        </details>

        <details className="rounded-lg border border-gray-200 dark:border-gray-800">
          <summary className="cursor-pointer px-4 py-2.5 font-medium text-gray-700 dark:text-gray-200">{t.other.name}</summary>
          <div className="px-4 pb-4">
            <Bullets items={t.other.steps} />
          </div>
        </details>
      </div>
    </div>
  );
}
