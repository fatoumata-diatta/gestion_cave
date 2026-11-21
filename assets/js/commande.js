/*
 * Formulaire de saisie d'une commande : lignes dynamiques et total en direct.
 *
 * IMPORTANT : ce script n'est qu'une aide à la saisie. Le serveur recalcule tout
 * (prix lus en base, stock verrouillé, total) : on ne lui fait jamais confiance.
 * Le formulaire ne bloque volontairement pas l'envoi quand le stock est dépassé,
 * pour que le refus (et l'annulation complète de la transaction) soit visible.
 */
(function () {
  'use strict';

  var corps = document.getElementById('lignes');
  var modele = document.getElementById('modele-ligne');
  var boutonAjouter = document.getElementById('ajouter-ligne');
  var champTotal = document.getElementById('total-commande');
  if (!corps || !modele || !boutonAjouter || !champTotal) {
    return;
  }

  var euros = new Intl.NumberFormat('fr-FR', { style: 'currency', currency: 'EUR' });
  var entiers = new Intl.NumberFormat('fr-FR');
  var prochainIndex = corps.querySelectorAll('tr.ligne').length;

  function lireLigne(ligne) {
    var liste = ligne.querySelector('select');
    var option = liste.options[liste.selectedIndex];
    var quantite = parseInt(ligne.querySelector('input').value, 10);
    return {
      id: liste.value,
      prix: option && option.value !== '' ? parseFloat(option.getAttribute('data-prix')) : 0,
      stock: option && option.value !== '' ? parseInt(option.getAttribute('data-stock'), 10) : null,
      quantite: isNaN(quantite) || quantite < 1 ? 0 : quantite
    };
  }

  function recalculer() {
    var lignes = Array.prototype.slice.call(corps.querySelectorAll('tr.ligne'));
    var donnees = lignes.map(lireLigne);

    // Quantité totale demandée par cuvée (une même cuvée saisie deux fois est additionnée par le serveur)
    var parCuvee = {};
    donnees.forEach(function (d) {
      if (d.id !== '') {
        parCuvee[d.id] = (parCuvee[d.id] || 0) + d.quantite;
      }
    });

    var total = 0;
    lignes.forEach(function (ligne, i) {
      var d = donnees[i];
      var cellulePrix = ligne.querySelector('.prix-ligne');
      var celluleStock = ligne.querySelector('.stock-ligne');
      var celluleTotal = ligne.querySelector('.sous-total');

      cellulePrix.textContent = d.id !== '' ? euros.format(d.prix) : '';
      celluleTotal.textContent = d.id !== '' && d.quantite > 0 ? euros.format(d.prix * d.quantite) : '';
      total += d.prix * d.quantite;

      celluleStock.classList.remove('depasse');
      if (d.id === '') {
        celluleStock.textContent = '';
      } else if (parCuvee[d.id] > d.stock) {
        celluleStock.textContent = 'Stock : ' + entiers.format(d.stock) + ' (insuffisant)';
        celluleStock.classList.add('depasse');
      } else {
        celluleStock.textContent = 'Stock : ' + entiers.format(d.stock);
      }
    });

    champTotal.textContent = euros.format(total);
  }

  function ajouterLigne() {
    var html = modele.innerHTML.replace(/__I__/g, String(prochainIndex));
    prochainIndex += 1;
    var conteneur = document.createElement('tbody');
    conteneur.innerHTML = html;
    var ligne = conteneur.querySelector('tr');
    corps.appendChild(ligne);
    ligne.querySelector('select').focus();
    recalculer();
  }

  boutonAjouter.addEventListener('click', ajouterLigne);

  corps.addEventListener('input', recalculer);
  corps.addEventListener('change', recalculer);
  corps.addEventListener('click', function (evenement) {
    var bouton = evenement.target.closest('.retirer-ligne');
    if (!bouton) {
      return;
    }
    var lignes = corps.querySelectorAll('tr.ligne');
    if (lignes.length > 1) {
      bouton.closest('tr').remove();
    } else {
      // Dernière ligne : on la vide au lieu de la supprimer
      var ligne = lignes[0];
      ligne.querySelector('select').value = '';
      ligne.querySelector('input').value = '';
    }
    recalculer();
  });

  recalculer();
})();
