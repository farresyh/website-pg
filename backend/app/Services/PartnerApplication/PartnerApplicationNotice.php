<?php

namespace App\Services\PartnerApplication;

use App\Models\PlatformSettings;

/**
 * ADR-133 decision 10: the two PDPA notices, bilingual (Malay and English, PDPA section 7),
 * versioned in code. The version is stored with each consent, so any wording change gets a new
 * version. The recipients are listed by class, not by company (ADR-133 2026-11-01 addendum).
 * The data user's name and address are read live from the company details in Settings.
 */
final class PartnerApplicationNotice
{
    public const VERSION = '2026-11-01';

    /**
     * @return array{version: string, en: string, ms: string}
     */
    public static function stage(int $stage): array
    {
        $settings = PlatformSettings::current();
        $legal = trim((string) $settings->company_legal_name);
        $address = filled($settings->company_address) ? ' ('.trim((string) $settings->company_address).')' : '';
        $reg = filled($settings->company_reg_no) ? ' ('.trim((string) $settings->company_reg_no).')' : '';
        // "LWF Group Sdn Bhd (123-X), operator of PekanGame (address)," or just "PekanGame" before Settings are filled.
        $en = $legal !== '' ? "{$legal}{$reg}, operator of PekanGame{$address}," : 'PekanGame';
        $ms = $legal !== '' ? "{$legal}{$reg}, pengendali PekanGame{$address}," : 'PekanGame';
        $contact = (string) config('services.onboarding.privacy_contact');

        return [
            'version' => self::VERSION,
            'en' => $stage === 1 ? self::stageOneEn($en, $contact) : self::stageTwoEn($en, $contact),
            'ms' => $stage === 1 ? self::stageOneMs($ms, $contact) : self::stageTwoMs($ms, $contact),
        ];
    }

    private static function stageOneEn(string $who, string $contact): string
    {
        return <<<TXT
        Privacy notice: reseller application (stage 1)

        {$who} collects the details in this form (your name, business name, phone, email and your answers about your reselling) to assess your application to become a PekanGame reseller and to contact you about it.

        Giving these details is voluntary, but without them we cannot assess your application. We do not ask for any document at this stage.

        Your details may be disclosed to: our payment service providers, on request, for compliance and verification; regulators, law enforcement and courts, where the law requires it; our professional advisers (lawyers, auditors, accountants), in confidence; and the service providers who store and send data for us (cloud storage and email), which may process it outside Malaysia.

        If your application is declined, we delete it 90 days after the decision. A deleted record may remain in our database backups until they expire.

        You may ask to access or correct your details, or to have them deleted, by writing to {$contact}.
        TXT;
    }

    private static function stageOneMs(string $who, string $contact): string
    {
        return <<<TXT
        Notis privasi: permohonan reseller (peringkat 1)

        {$who} mengumpul maklumat dalam borang ini (nama, nama perniagaan, telefon, emel dan jawapan anda tentang perniagaan reseller anda) untuk menilai permohonan anda menjadi reseller PekanGame dan untuk menghubungi anda tentangnya.

        Pemberian maklumat ini adalah secara sukarela, tetapi tanpanya kami tidak dapat menilai permohonan anda. Kami tidak meminta sebarang dokumen pada peringkat ini.

        Maklumat anda mungkin didedahkan kepada: penyedia perkhidmatan pembayaran kami, apabila diminta, untuk tujuan pematuhan dan pengesahan; pihak berkuasa kawal selia, penguat kuasa undang-undang dan mahkamah, apabila dikehendaki oleh undang-undang; penasihat profesional kami (peguam, juruaudit, akauntan), secara sulit; dan penyedia perkhidmatan yang menyimpan dan menghantar data bagi pihak kami (storan awan dan emel), yang mungkin memproses data di luar Malaysia.

        Jika permohonan anda ditolak, kami memadamkannya 90 hari selepas keputusan dibuat. Rekod yang telah dipadam mungkin kekal dalam salinan sandaran pangkalan data kami sehingga salinan itu luput.

        Anda boleh meminta untuk mengakses atau membetulkan maklumat anda, atau memadamkannya, dengan menulis kepada {$contact}.
        TXT;
    }

    private static function stageTwoEn(string $who, string $contact): string
    {
        return <<<TXT
        Privacy notice: reseller documents (stage 2)

        {$who} collects your address, your tax identification number (if you give it) and the documents you upload (SSM certificate or identity card, and bank statements) to verify your identity and business before opening your reseller account, and to meet the requirements of our payment service providers.

        The documents are required: without them we cannot open your account. You may black out the transaction lines on your bank statement. The documents are kept in private storage; only authorised staff can open them, and every opening is recorded.

        Your details and documents may be disclosed to: our payment service providers, on request, for compliance and verification; regulators, law enforcement and courts, where the law requires it; our professional advisers (lawyers, auditors, accountants), in confidence; and the service providers who store and send data for us (cloud storage in the Asia-Pacific region, and email), which may process it outside Malaysia.

        If your application is declined, we delete your details and documents 90 days after the decision. If it is approved, we keep them while your account is open and delete them 90 days after it is closed. Your orders, top-ups and receipts are kept separately, as the law requires. A deleted record may remain in our database backups until they expire; the documents themselves are never in those backups.

        You may ask to access or correct your details, or to have them deleted, by writing to {$contact}.
        TXT;
    }

    private static function stageTwoMs(string $who, string $contact): string
    {
        return <<<TXT
        Notis privasi: dokumen reseller (peringkat 2)

        {$who} mengumpul alamat anda, nombor pengenalan cukai anda (jika diberikan) dan dokumen yang anda muat naik (sijil SSM atau kad pengenalan, dan penyata bank) untuk mengesahkan identiti dan perniagaan anda sebelum membuka akaun reseller anda, dan untuk memenuhi keperluan penyedia perkhidmatan pembayaran kami.

        Dokumen ini diwajibkan: tanpanya kami tidak dapat membuka akaun anda. Anda boleh menghitamkan baris transaksi pada penyata bank anda. Dokumen disimpan dalam storan persendirian; hanya kakitangan yang diberi kuasa boleh membukanya, dan setiap pembukaan direkodkan.

        Maklumat dan dokumen anda mungkin didedahkan kepada: penyedia perkhidmatan pembayaran kami, apabila diminta, untuk tujuan pematuhan dan pengesahan; pihak berkuasa kawal selia, penguat kuasa undang-undang dan mahkamah, apabila dikehendaki oleh undang-undang; penasihat profesional kami (peguam, juruaudit, akauntan), secara sulit; dan penyedia perkhidmatan yang menyimpan dan menghantar data bagi pihak kami (storan awan di rantau Asia Pasifik, dan emel), yang mungkin memproses data di luar Malaysia.

        Jika permohonan anda ditolak, kami memadamkan maklumat dan dokumen anda 90 hari selepas keputusan dibuat. Jika diluluskan, kami menyimpannya selagi akaun anda dibuka dan memadamkannya 90 hari selepas akaun ditutup. Pesanan, tambah nilai dan resit anda disimpan secara berasingan seperti yang dikehendaki oleh undang-undang. Rekod yang telah dipadam mungkin kekal dalam salinan sandaran pangkalan data kami sehingga salinan itu luput; dokumen itu sendiri tidak pernah berada dalam salinan sandaran tersebut.

        Anda boleh meminta untuk mengakses atau membetulkan maklumat anda, atau memadamkannya, dengan menulis kepada {$contact}.
        TXT;
    }
}
