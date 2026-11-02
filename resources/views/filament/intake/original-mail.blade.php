{{-- Mail HTML only in a sandboxed iframe, served with its own CSP (R13). --}}
<iframe
    sandbox=""
    src="{{ $src }}"
    title="Original-Mail"
    referrerpolicy="no-referrer"
    loading="lazy"
    class="block h-[36rem] w-full border-0 bg-white"
></iframe>
