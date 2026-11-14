// Control Panel entry point. Components, fieldtypes and Inertia pages register inside the
// booting callback so they run once Statamic has initialized.
import MarketingRedirectCreate from './pages/MarketingRedirectCreate.vue';
import MarketingRedirectReview from './pages/MarketingRedirectReview.vue';
import QrCodeDownload from './pages/QrCodeDownload.vue';

Statamic.booting(() => {
    // The marketing-redirect creation utility and its review queue, rendered by
    // MarketingRedirectController
    Statamic.$inertia.register('aether-redirects::MarketingRedirectCreate', MarketingRedirectCreate);
    Statamic.$inertia.register('aether-redirects::MarketingRedirectReview', MarketingRedirectReview);
    // The files of a vanity URL's QR code, rendered by QrCodeController
    Statamic.$inertia.register('aether-redirects::QrCodeDownload', QrCodeDownload);
});
