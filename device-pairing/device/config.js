/**
 * The only file you edit when you deploy.
 *
 * Point this at wherever api.php actually lives. The device page is plain
 * static HTML, so it can sit on Netlify, GitHub Pages, Vercel or any CDN —
 * but the API must run on a host that executes PHP.
 *
 * No trailing slash.
 */
window.API_BASE = 'http://localhost:8000';

// Live example:
// window.API_BASE = 'https://api.yoursite.com';

/** Shown on screen so the viewer knows where to go. Cosmetic only. */
window.ACTIVATE_URL = 'localhost:8000/activate.php';
