/**
 * Copyright (C) 2017-2026 thirty bees
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * that is bundled with this package in the file LICENSE.md.
 * It is also available through the world-wide-web at this URL:
 * https://opensource.org/licenses/afl-3.0.php
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@thirtybees.com so we can send you a copy immediately.
 *
 * @author    thirty bees <modules@thirtybees.com>
 * @copyright 2017-2026 thirty bees
 * @license   Academic Free License (AFL 3.0)
 */

/*
 * "Check now at VIES" on the order page: asks the module, shows the answer in
 * a popup and updates the status label, without leaving the order. All texts
 * come translated from the panel template (data attributes) and the server.
 */
$(function () {
  var $button = $('#vatnumber-check-now');
  if (!$button.length) {
    return;
  }
  // A modal inside a column panel can end up below its own backdrop.
  var $modal = $('#vatnumber-check-modal').appendTo('body');
  var $body = $('#vatnumber-check-result');

  function line(label, value) {
    return $('<p>').append($('<b>').text(label + ': ')).append(document.createTextNode(value));
  }

  function showResult(result) {
    var alertClass = {
      valid: 'alert-success',
      invalid: 'alert-warning',
      unavailable: 'alert-warning'
    }[result.state] || 'alert-danger';

    $body.empty();
    // The server sends translated messages that are already HTML escaped.
    $body.append($('<div class="alert">').addClass(alertClass).html(result.message));
    if (result.trader_name) {
      $body.append(line($button.data('textTrader'), result.trader_name));
    }
    if (result.trader_address) {
      $body.append(line($button.data('textAddress'), result.trader_address));
    }
    if (result.note) {
      $body.append($('<p>').append($('<i>').html(result.note)));
    }
    if (result.request_id) {
      $body.append($('<p>').text($button.data('textProof') + ' ' + result.request_id));
    }
    if (result.badge) {
      $('#vatnumber-order-panel .vatnumber-badge').empty().append(
        $('<span class="label">').addClass(result.badge['class']).text(result.badge.label)
      );
    }
  }

  $button.on('click', function () {
    $body.empty().append($('<p>').text($button.data('textChecking')));
    $modal.modal('show');
    $button.prop('disabled', true);
    $.ajax({
      url: $button.data('url'),
      type: 'POST',
      dataType: 'json',
      data: {
        ajax: 1,
        action: 'checkVatNumber',
        id_order: $button.data('idOrder')
      }
    }).done(function (result) {
      if (result && result.state) {
        showResult(result);
      } else {
        $body.empty().append($('<div class="alert alert-danger">').text($button.data('textFailed')));
      }
    }).fail(function () {
      $body.empty().append($('<div class="alert alert-danger">').text($button.data('textFailed')));
    }).always(function () {
      $button.prop('disabled', false);
    });
  });
});
