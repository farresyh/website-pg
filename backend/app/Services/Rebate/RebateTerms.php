<?php

namespace App\Services\Rebate;

/**
 * ADR-139 programme terms, version 1 (founder-approved, recorded verbatim in
 * the ADR's terms addendum). Changing the text means a new VERSION, which
 * needs re-acceptance at the next claim.
 */
final class RebateTerms
{
    public const VERSION = 1;

    public const TITLE = 'Terma Program Rebate PekanGame';

    /** @var list<string> */
    public const CLAUSES = [
        'Rebate dikira berdasarkan jumlah belian layak reseller dalam setiap bulan kalendar. Belian layak ialah belian yang berjaya diproses dan kreditnya telah dihantar. Belian yang dibatalkan, dipulangkan atau dikenakan chargeback tidak dikira.',
        'Jumlah belian layak dan ganjaran bagi setiap tier adalah seperti yang disenaraikan dalam jadual Program Rebate PekanGame. Sebaik sahaja jumlah belian layak bulan itu mencapai sesuatu tier, ganjaran tier tersebut boleh dituntut. Ganjaran tertakluk kepada had bulanan program dan mungkin dilaraskan.',
        'Ganjaran yang layak diterima boleh dituntut sehingga hari terakhir bulan berikutnya. Ganjaran yang tidak dituntut sebelum tarikh itu akan luput.',
        'Ganjaran yang dituntut akan dikreditkan ke Purchase Balance dan hanya boleh digunakan untuk belian di platform PekanGame. Ganjaran tidak boleh ditunaikan, dikeluarkan sebagai wang tunai atau dipindahkan kepada akaun lain.',
        'Jika akaun reseller ditutup, baki ganjaran rebate yang masih ada akan luput. Baki Purchase Balance daripada top-up tunai akan dipulangkan selepas semakan akaun dan sebarang amaun yang masih perlu dibayar diselesaikan.',
        'PekanGame boleh membetulkan pengiraan ganjaran jika terdapat kesilapan atau transaksi yang tidak layak, seperti transaksi yang dibatalkan atau dipulangkan. Sebarang pembetulan akan dimaklumkan kepada reseller.',
        'PekanGame boleh mengubah tier atau jumlah ganjaran dengan memberikan notis. Perubahan hanya berkuat kuasa mulai bulan berikutnya dan tidak mengubah ganjaran yang reseller sudah layak terima bagi bulan sebelumnya.',
    ];
}
