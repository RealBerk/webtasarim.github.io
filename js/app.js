/* Wellness Sağlık - Uygulama JS
   - Hizmetler dropdown: animasyonlu aç/kapa
   - Sayaçlar: sayfa açılınca 0'dan hedefe animasyon
   - Yorumlar: listele, puan ortalaması, form gönderimi (admin onaylı)
   - Ayarlar: logo_url varsa logoyu uygular
   - Harita/iletişim: CSS ile responsive; burada ek JS yok
*/

(function () {
  "use strict";

  // === Kısayollar ===
  const qs  = (sel, el=document) => el.querySelector(sel);
  const qsa = (sel, el=document) => Array.from(el.querySelectorAll(sel));
  const on  = (el, evt, fn) => el && el.addEventListener(evt, fn);

  // === Basit i18n (minimum) ===
  function t(key) {
    const dict = {
      "form.received": "Bilgilerinizi aldık, en kısa sürede dönüş yapacağız.",
      "reviews.submitted": "Yorumunuzu aldık. Onaylandıktan sonra yayınlanacak.",
      "reviews.error": "Şu an gönderilemedi. Daha sonra tekrar deneyin."
    };
    return dict[key] || key;
  }
  window.t = t; // index.html içindeki formlar kullanıyor

  // === Hizmetler dropdown (animasyonlu) ===
  const hizmetlerBtn = qs("#hizmetlerBtn");
  const hizmetlerDropdown = qs("#hizmetlerDropdown");
  const hasDropdown = hizmetlerBtn ? hizmetlerBtn.closest(".has-dropdown") : null;

  function closeDropdown() {
    if (!hizmetlerDropdown) return;
    hizmetlerDropdown.classList.remove("active");
    hizmetlerBtn && hizmetlerBtn.setAttribute("aria-expanded", "false");
  }

  function toggleDropdown() {
    if (!hizmetlerDropdown) return;
    const isOpen = hizmetlerDropdown.classList.contains("active");
    if (isOpen) {
      closeDropdown();
    } else {
      hizmetlerDropdown.classList.add("active");
      hizmetlerBtn && hizmetlerBtn.setAttribute("aria-expanded", "true");
    }
  }

  // Globalde kullanılmak üzere
  window.menuClickOpen = function () {
    toggleDropdown();
  };

  // Dışarı tıklayınca kapat
  on(document, "click", (e) => {
    if (!hasDropdown) return;
    if (!hasDropdown.contains(e.target)) {
      closeDropdown();
    }
  });

  // ESC ile kapat
  on(document, "keydown", (e) => {
    if (e.key === "Escape") closeDropdown();
  });

  // === Sayaçlar ===
  function animateValue(el, start, end, duration = 1200) {
    const startTs = performance.now();
    function frame(now) {
      const prog = Math.min(1, (now - startTs) / duration);
      const val  = Math.floor(start + (end - start) * prog);
      el.textContent = val.toString();
      if (prog < 1) requestAnimationFrame(frame);
    }
    requestAnimationFrame(frame);
  }

  function initCounters() {
    qsa(".counter").forEach(el => {
      const target = parseInt(el.getAttribute("data-count") || "0", 10);
      animateValue(el, 0, target, 1200);
    });
  }

  // === Yorumlar ===
  const reviewListEl  = qs("#reviewList");
  const reviewForm    = qs("#reviewForm");
  const avgScoreEl    = qs("#avgScore");
  const revCountEl    = qs("#revCount");
  const avgStarsEl    = qs("#avgStars");

  async function fetchJSON(url, opts) {
    const res = await fetch(url, opts || { cache: "no-store" });
    if (!res.ok) throw new Error(`HTTP ${res.status}`);
    return await res.json();
  }

  function renderStars(avg) {
    const rounded = Math.round(avg * 2) / 2; // .5 adımlar
    const full = Math.floor(rounded);
    const half = rounded - full >= 0.5 ? 1 : 0;
    const empty = 5 - full - half;
    return "★".repeat(full) + (half ? "½" : "") + "☆".repeat(empty);
  }

  function renderReviews(reviews) {
    if (!reviewListEl) return;
    reviewListEl.innerHTML = reviews.map(r => `
      <div class="review">
        <div class="meta"><strong>${escapeHTML(r.name)}</strong>${r.country ? " • " + escapeHTML(r.country) : ""} • ${"★".repeat(r.rating)}</div>
        <div class="msg">${escapeHTML(r.message)}</div>
      </div>
    `).join("");

    const count = reviews.length;
    const avg = count ? (reviews.reduce((a, b) => a + (b.rating || 0), 0) / count) : 0;
    if (avgScoreEl)  avgScoreEl.textContent = avg.toFixed(1);
    if (revCountEl)  revCountEl.textContent = String(count);
    if (avgStarsEl)  avgStarsEl.textContent = "★".repeat(Math.round(avg)) || "★★★★★";
  }

  function escapeHTML(s) {
    return String(s).replace(/[&<>"']/g, m => ({ "&":"&amp;", "<":"&lt;", ">":"&gt;", '"':"&quot;", "'":"&#039;" }[m]));
  }

  async function loadReviews() {
    // Önce backend (admin.php) dene, olmazsa localStorage
    try {
      const data = await fetchJSON("admin.php?action=list_reviews&public=1");
      const reviews = (data && data.reviews) ? data.reviews : [];
      renderReviews(reviews);
    } catch (e) {
      // Fallback: localStorage
      const ls = JSON.parse(localStorage.getItem("reviews_public") || "[]");
      renderReviews(ls);
    }
  }

  on(reviewForm, "submit", async (e) => {
    e.preventDefault();
    try {
      const rating = parseInt((qs('input[name="rating"]:checked', reviewForm) || {}).value || "0", 10);
      const name = qs("#revName", reviewForm).value.trim();
      const country = qs("#revCountry", reviewForm).value.trim();
      const message = qs("#revMsg", reviewForm).value.trim();

      if (!rating || !name || !message) {
        alert("Lütfen ad, puan ve mesaj alanlarını doldurun.");
        return;
      }

      // Backend'e gönder
      const res = await fetchJSON("admin.php?action=submit_review", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ name, country, message, rating })
      });

      if (res && res.ok) {
        alert(t("reviews.submitted"));
        reviewForm.reset();
        // Onay bekliyor; mevcut listeyi yeniden çekmeye gerek yok
      } else {
        throw new Error("Server response not ok");
      }
    } catch (err) {
      alert(t("reviews.error"));
    }
  });

  // === Ayarlar (logo) ===
  async function applySettings() {
    try {
      const data = await fetchJSON("admin.php?action=settings");
      const logo = data && data.settings ? data.settings.logo_url : "";
      if (logo) {
        const logoEl = qs(".brand-logo");
        if (logoEl) {
          logoEl.innerHTML = `<img src="${logo}" alt="Logo" style="width:100%;height:100%;border-radius:50%;object-fit:cover;">`;
          logoEl.style.background = "transparent";
          logoEl.style.boxShadow = "none";
        }
      }
    } catch (_) { /* sessiz geç */ }
  }

  // === Mobil menü (bonus küçük iyileştirme) ===
  const menuToggle = qs(".menu-toggle");
  const body = document.body;
  const mobileMenu = qs("#mobileMenu");
  on(menuToggle, "click", () => {
    body.classList.toggle("mobile-open");
    if (mobileMenu) {
      const opened = body.classList.contains("mobile-open");
      mobileMenu.style.display = opened ? "block" : "none";
    }
  });

  // === Başlat ===
  window.addEventListener("load", () => {
    initCounters();    // site açılınca sayaçlar çalışır
    loadReviews();     // yorumları çek
    applySettings();   // logo ayarını uygula
  });
})();