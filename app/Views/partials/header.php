<!-- NAVIGATION -->
<header id="navbar" class="w-full">
<nav class="max-w-[1280px] mx-auto px-6 py-4 flex items-center justify-between">
  <a href="/" class="flex items-center gap-2.5 flex-shrink-0" aria-label="Edvora home">
    <div class="w-8 h-8 flex-shrink-0" style="filter: drop-shadow(0 3px 10px rgba(6,61,59,0.22))">
      <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full h-full block">
        <rect width="40" height="40" rx="11" fill="#063D3B"/>
        <path d="M20 6C20 13.732 13.732 20 6 20C13.732 20 20 26.268 20 34C20 26.268 26.268 20 34 20C26.268 20 20 13.732 20 6Z" fill="#C8FF63"/>
      </svg>
    </div>
    <span class="font-extrabold text-[19px] tracking-tight leading-none" style="color:#063D3B">Edvora<span style="color:#0D6E6A">Chat</span></span>
  </a>
  <ul class="hidden lg:flex items-center gap-8 list-none">
    <li><a href="/#platform" class="text-[14px] font-medium text-e-muted hover:text-e-teal transition-colors">Platform</a></li>
    <li><a href="/#how-it-works" class="text-[14px] font-medium text-e-muted hover:text-e-teal transition-colors">How It Works</a></li>
    <li><a href="/pricing" class="text-[14px] font-medium text-e-muted hover:text-e-teal transition-colors">Pricing</a></li>
  </ul>
  <div class="hidden lg:flex items-center gap-3">
    <a href="/app" class="btn-ghost text-[14px] px-4 py-2">Sign In</a>
    <a href="/app#signup" class="btn-secondary text-[14px] px-4 py-2">Register College</a>
    <button onclick="openDemo()" class="btn-primary text-[14px] px-5 py-2.5">Book a Demo &rarr;</button>
  </div>
  <button class="lg:hidden p-2 rounded-lg hover:bg-white/60 transition-colors" onclick="toggleMenu()" aria-label="Open menu">
    <svg width="22" height="16" viewBox="0 0 22 16" fill="none"><rect width="22" height="2" rx="1" fill="#063D3B"/><rect y="7" width="16" height="2" rx="1" fill="#063D3B"/><rect y="14" width="22" height="2" rx="1" fill="#063D3B"/></svg>
  </button>
</nav>
</header>

<!-- MOBILE MENU -->
<div id="mobile-menu" role="dialog" aria-modal="true">
  <div class="flex items-center justify-between mb-10">
    <a href="/" class="flex items-center gap-2.5">
      <div class="w-8 h-8 flex-shrink-0" style="filter: drop-shadow(0 3px 10px rgba(6,61,59,0.22))">
        <svg viewBox="0 0 40 40" fill="none" xmlns="http://www.w3.org/2000/svg" class="w-full h-full block">
          <rect width="40" height="40" rx="11" fill="#063D3B"/>
          <path d="M20 6C20 13.732 13.732 20 6 20C13.732 20 20 26.268 20 34C20 26.268 26.268 20 34 20C26.268 20 20 13.732 20 6Z" fill="#C8FF63"/>
        </svg>
      </div>
      <span class="font-extrabold text-[19px] tracking-tight leading-none" style="color:#063D3B">Edvora<span style="color:#0D6E6A">Chat</span></span>
    </a>
    <button onclick="toggleMenu()" aria-label="Close menu"><svg width="18" height="18" viewBox="0 0 18 18" fill="none"><line x1="1" y1="1" x2="17" y2="17" stroke="#063D3B" stroke-width="2" stroke-linecap="round"/><line x1="17" y1="1" x2="1" y2="17" stroke="#063D3B" stroke-width="2" stroke-linecap="round"/></svg></button>
  </div>
  <nav class="flex flex-col gap-2 mb-10">
    <a href="/#platform" onclick="toggleMenu()" class="text-[17px] font-semibold text-e-teal py-3 px-4 rounded-xl hover:bg-white/60">Platform</a>
    <a href="/#how-it-works" onclick="toggleMenu()" class="text-[17px] font-semibold text-e-teal py-3 px-4 rounded-xl hover:bg-white/60">How It Works</a>
    <a href="/pricing" onclick="toggleMenu()" class="text-[17px] font-semibold text-e-teal py-3 px-4 rounded-xl hover:bg-white/60">Pricing</a>
  </nav>
  <div class="flex flex-col gap-3 mt-auto">
    <a href="/app" class="btn-secondary text-[15px] px-5 py-3 justify-center">Sign In</a>
    <a href="/app#signup" class="btn-secondary text-[15px] px-5 py-3 justify-center">Register College</a>
    <button onclick="openDemo();toggleMenu();" class="btn-primary text-[15px] px-5 py-3 justify-center">Book a Demo &rarr;</button>
  </div>
</div>
