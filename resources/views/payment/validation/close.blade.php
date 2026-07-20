<script>
    function closeBrowser() {
        if (window.ReactNativeWebView) {
            window.ReactNativeWebView.postMessage(JSON.stringify({ action: 'closeBrowser' }));
        }
    }

</script>
