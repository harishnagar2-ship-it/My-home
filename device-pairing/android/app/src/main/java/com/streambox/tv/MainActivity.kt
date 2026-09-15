package com.streambox.tv

import android.annotation.SuppressLint
import android.os.Bundle
import android.view.KeyEvent
import android.view.WindowManager
import android.webkit.WebChromeClient
import android.webkit.WebView
import android.webkit.WebViewClient
import androidx.appcompat.app.AppCompatActivity

/**
 * The whole app: a WebView pointed at the StreamBox device page.
 *
 * The web page already handles pairing, polling and broadcast playback, so
 * there is nothing to duplicate here. This class only has to give it a browser
 * that behaves properly on a television.
 */
class MainActivity : AppCompatActivity() {

    private lateinit var web: WebView

    @SuppressLint("SetJavaScriptEnabled")
    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        setContentView(R.layout.activity_main)

        // A pairing code nobody can read because the screen slept is a support call.
        window.addFlags(WindowManager.LayoutParams.FLAG_KEEP_SCREEN_ON)

        web = findViewById(R.id.web)
        web.settings.apply {
            javaScriptEnabled = true

            // The page stores its device_code in localStorage so the TV stays
            // paired across reboots. Without this, it asks for a new code every launch.
            domStorageEnabled = true

            // Broadcasts are meant to start on their own — nobody presses play on a TV.
            mediaPlaybackRequiresUserGesture = false

            loadWithOverviewMode = true
            useWideViewPort = true
        }

        // Keep navigation inside the app rather than throwing the viewer into a browser.
        web.webViewClient = WebViewClient()
        web.webChromeClient = WebChromeClient()

        web.loadUrl(getString(R.string.app_url))
    }

    /** The TV remote's Back button should step back through the app, not exit it. */
    override fun onKeyDown(keyCode: Int, event: KeyEvent?): Boolean {
        if (keyCode == KeyEvent.KEYCODE_BACK && web.canGoBack()) {
            web.goBack()
            return true
        }
        return super.onKeyDown(keyCode, event)
    }
}
