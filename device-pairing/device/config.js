/**
 * The only file you edit when you deploy.
 *
 * The device app is plain static HTML, so it can sit on Netlify, GitHub Pages,
 * Vercel or any CDN — but the API must run on a host that executes PHP.
 *
 * No trailing slash on API_BASE.
 */
window.API_BASE = 'http://localhost:8000';

// Live example:
// window.API_BASE = 'https://yoursite.com/tv';

/** Shown on the TV so the viewer knows where to type the code. Cosmetic. */
window.ACTIVATE_URL = 'localhost:8000/activate.php';

/** How often a paired screen checks for a new broadcast, in seconds. */
window.SYNC_SECONDS = 8;

/** Where the Download button points. Upload your built APK and put its URL here. */
window.APK_URL = 'streambox-tv.apk';
