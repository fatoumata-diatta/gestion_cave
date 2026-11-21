/*
 * Comportements communs à toutes les pages (aucune bibliothèque externe).
 *  1. Confirmation avant une action destructrice : <form data-confirm="Message ?">
 *  2. Info-bulles sur les graphiques : tout élément portant data-tip-valeur et data-tip-libelle
 *     (au survol à la souris ET au focus clavier ; le texte est inséré avec textContent).
 */
(function () {
  'use strict';

  // --- 1. Confirmations -----------------------------------------------------
  document.addEventListener('submit', function (evenement) {
    var message = evenement.target.getAttribute && evenement.target.getAttribute('data-confirm');
    if (message && !window.confirm(message)) {
      evenement.preventDefault();
    }
  });

  // --- 2. Info-bulles ---------------------------------------------------------
  var bulle = null;

  function obtenirBulle() {
    if (!bulle) {
      bulle = document.createElement('div');
      bulle.className = 'info-bulle';
      bulle.setAttribute('role', 'tooltip');
      bulle.hidden = true;
      document.body.appendChild(bulle);
    }
    return bulle;
  }

  function remplir(element) {
    var b = obtenirBulle();
    b.textContent = '';
    var valeur = document.createElement('strong');
    valeur.textContent = element.getAttribute('data-tip-valeur') || '';
    var libelle = document.createElement('span');
    libelle.textContent = element.getAttribute('data-tip-libelle') || '';
    b.appendChild(valeur);
    b.appendChild(libelle);
    b.hidden = false;
  }

  function placer(x, y) {
    var b = obtenirBulle();
    var largeur = b.offsetWidth;
    var hauteur = b.offsetHeight;
    var gauche = Math.min(x + 14, window.innerWidth - largeur - 8);
    var haut = y - hauteur - 12;
    if (haut < 8) {
      haut = y + 18;
    }
    b.style.left = Math.max(8, gauche) + 'px';
    b.style.top = haut + 'px';
  }

  function masquer() {
    if (bulle) {
      bulle.hidden = true;
    }
  }

  function cible(evenement) {
    return evenement.target.closest ? evenement.target.closest('[data-tip-valeur]') : null;
  }

  document.addEventListener('pointerover', function (evenement) {
    var element = cible(evenement);
    if (element) {
      remplir(element);
      placer(evenement.clientX, evenement.clientY);
    }
  });
  document.addEventListener('pointermove', function (evenement) {
    if (bulle && !bulle.hidden && cible(evenement)) {
      placer(evenement.clientX, evenement.clientY);
    }
  });
  document.addEventListener('pointerout', function (evenement) {
    if (cible(evenement)) {
      masquer();
    }
  });
  document.addEventListener('focusin', function (evenement) {
    var element = cible(evenement);
    if (element) {
      remplir(element);
      var cadre = element.getBoundingClientRect();
      placer(cadre.left + cadre.width / 2, cadre.top);
    }
  });
  document.addEventListener('focusout', masquer);
  document.addEventListener('keydown', function (evenement) {
    if (evenement.key === 'Escape') {
      masquer();
    }
  });
})();
