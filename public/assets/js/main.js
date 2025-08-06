"use strict";

/**
 * Element toggle function
 */
const elemToggleFunc = function (elem) {
  elem.classList.toggle("active");
};

/**
 * Navbar variables
 */
const navbar = document.querySelector("[data-nav]");
const navOpenBtn = document.querySelector("[data-nav-open-btn]");
const navCloseBtn = document.querySelector("[data-nav-close-btn]");
const overlay = document.querySelector("[data-overlay]");

const navElemArr = [navOpenBtn, navCloseBtn, overlay];

navElemArr.forEach((element) => {
  element.addEventListener("click", function () {
    elemToggleFunc(navbar);
    elemToggleFunc(overlay);
    elemToggleFunc(document.body);
  });
});

/**
 * Go to top button functionality
 */
const goTopBtn = document.querySelector("[data-go-top]");

window.addEventListener("scroll", function () {
  if (window.scrollY >= 800) {
    goTopBtn.classList.add("active");
  } else {
    goTopBtn.classList.remove("active");
  }
});

/**
 * FAQ Toggle
 */
document.querySelectorAll(".faq-question").forEach((btn) => {
  btn.addEventListener("click", function () {
    this.parentElement.classList.toggle("active");
  });
});


/**
 * JavaScript to Detect Hover Direction on .winner-card
 */
document.querySelectorAll(".winner-card").forEach((card) => {
  card.addEventListener("mousemove", (e) => {
    const rect = card.getBoundingClientRect();
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;
    const w = rect.width;
    const h = rect.height;

    if (y < h * 0.2) {
      card.setAttribute("data-direction", "top");
    } else if (y > h * 0.8) {
      card.setAttribute("data-direction", "bottom");
    } else if (x < w * 0.2) {
      card.setAttribute("data-direction", "left");
    } else if (x > w * 0.8) {
      card.setAttribute("data-direction", "right");
    }
  });
});

// Preloader
document.addEventListener('DOMContentLoaded', () => {
    const preloader = document.querySelector('.preloader');
    
    // Simulate loading progress (you can modify this based on actual loading needs)
    let progress = 0;
    const progressBar = document.querySelector('.loading-progress');
    const loadingText = document.querySelector('.loading-text');
    
    const updateProgress = () => {
        if (progress < 100) {
            progress += Math.random() * 30;
            if (progress > 100) progress = 100;
            
            progressBar.style.width = `${progress}%`;
            loadingText.textContent = `Loading... ${Math.floor(progress)}%`;
            
            if (progress < 100) {
                setTimeout(updateProgress, 200 + Math.random() * 500);
            } else {
                setTimeout(() => {
                    preloader.classList.add('fade-out');
                    setTimeout(() => {
                        preloader.style.display = 'none';
                    }, 500);
                }, 500);
            }
        }
    };
    
    // Start progress animation
    setTimeout(updateProgress, 500);
});

document.addEventListener("DOMContentLoaded", function () {
  // Navbar Dropdowns - Keep this section as it handles navigation dropdowns
  const navbarDropdowns = document.querySelectorAll(".navbar .dropdown");
  navbarDropdowns.forEach((dropdown) => {
    const toggle = dropdown.querySelector(".dropdown-toggle");
    
    if (toggle) {
      toggle.addEventListener("click", function (e) {
        e.preventDefault();
        e.stopPropagation();
        dropdown.classList.toggle("active");

        // Close other navbar dropdowns
        navbarDropdowns.forEach((item) => {
          if (item !== dropdown) {
            item.classList.remove("active");
          }
        });
      });
    }
  });

  // Close navbar dropdowns when clicking outside
  document.addEventListener("click", function (e) {
    if (!e.target.closest('.navbar .dropdown')) {
      navbarDropdowns.forEach((dropdown) => {
        dropdown.classList.remove("active");
      });
    }
  });

  // Mobile logo toggle functionality
  function toggleMobileLogo() {
    const mobileLogo = document.querySelector(".logo-mobile-only");
    if (mobileLogo) {
      if (window.innerWidth > 768) {
        mobileLogo.style.display = "none";  // Hide on large screens
      } else {
        mobileLogo.style.display = "block"; // Show on small screens
      }
    }
  }

  // Run the function on load and resize
  toggleMobileLogo();
  window.addEventListener("resize", toggleMobileLogo);

  // Notification badge handling
  const notifBadge = document.getElementById("notif-count");
  const notifDropdown = document.getElementById("notif-dropdown");
  
  if (notifBadge && notifDropdown) {
    // Hide badge if no notifications
    if (notifDropdown.children.length === 0) {
      notifBadge.classList.add("hidden");
    }
  }

  // *** IMPORTANT: REMOVED CONFLICTING DROPDOWN CODE ***
  // The header dropdowns are now handled by the script in header.php
});

