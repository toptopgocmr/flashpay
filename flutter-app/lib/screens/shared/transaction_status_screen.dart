import 'dart:async';
import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../services/checkout.dart';
import '../../models/quote.dart';
import '../../providers/session_provider.dart';
import '../../services/payment_service.dart';
import '../../services/receipt.dart';
import '../../l10n/l10n.dart';

/// Suivi en temps réel d'une opération : étapes (demande, validation sur le
/// téléphone, versement, terminé), chronomètre d'attente, conseils si
/// l'opérateur tarde, puis reçu à consulter ou partager.
class TransactionStatusScreen extends StatefulWidget {
  final FpPaymentStatus initial;
  final String title;

  const TransactionStatusScreen({super.key, required this.initial, this.title = 'Opération'});

  @override
  State<TransactionStatusScreen> createState() => _TransactionStatusScreenState();
}

class _TransactionStatusScreenState extends State<TransactionStatusScreen> {
  final _service = PaymentService();
  late FpPaymentStatus _s = widget.initial;
  final _clock = Stopwatch();
  Timer? _tick; // rafraîchit le chronomètre chaque seconde
  Timer? _poll;
  bool _polling = false;
  bool _gaveUp = false;
  late bool _viaPhone = widget.initial.stage == 'awaiting_source';
  late bool _viaCard = widget.initial.stage == 'awaiting_card';
  late bool _payoutSeen = widget.initial.stage == 'awaiting_destination';

  static const _giveUpAfter = Duration(minutes: 15);

  @override
  void initState() {
    super.initState();
    if (_s.isPending) _start();
  }

  void _start() {
    _gaveUp = false;
    _clock
      ..reset()
      ..start();
    _tick?.cancel();
    _tick = Timer.periodic(Duration(seconds: 1), (_) {
      if (mounted) setState(() {});
    });
    _schedule();
  }

  /// Interrogation rapide au début, puis plus espacée : 3 s (1re minute),
  /// 6 s (jusqu'à 5 min), 12 s ensuite ; arrêt au bout de 15 min.
  void _schedule() {
    final e = _clock.elapsed;
    if (e >= _giveUpAfter) {
      _stop(gaveUp: true);
      return;
    }
    final every = e < Duration(minutes: 1)
        ? Duration(seconds: 3)
        : e < Duration(minutes: 5)
            ? Duration(seconds: 6)
            : Duration(seconds: 12);
    _poll = Timer(every, _check);
  }

  Future<void> _check() async {
    if (_polling) return;
    _polling = true;
    try {
      final s = await _service.status(_s.id);
      if (!mounted) return;
      setState(() {
        _s = s;
        if (s.stage == 'awaiting_source') _viaPhone = true;
        if (s.stage == 'awaiting_card') _viaCard = true;
        if (s.stage == 'awaiting_destination') _payoutSeen = true;
      });
      if (!s.isPending) {
        _stop();
        context.read<SessionProvider>().refreshUser().catchError((_) {});
        return;
      }
    } catch (_) {
      /* réseau : on réessaie au prochain passage */
    } finally {
      _polling = false;
    }
    if (mounted && _s.isPending) _schedule();
  }

  void _stop({bool gaveUp = false}) {
    _poll?.cancel();
    _tick?.cancel();
    _clock.stop();
    if (mounted) setState(() => _gaveUp = gaveUp);
  }

  @override
  void dispose() {
    _poll?.cancel();
    _tick?.cancel();
    super.dispose();
  }

  String get _elapsed {
    final e = _clock.elapsed;
    final m = e.inMinutes.toString().padLeft(2, '0');
    final sec = (e.inSeconds % 60).toString().padLeft(2, '0');
    return '$m:$sec';
  }

  /// Étapes affichées : (libellé, état) avec état = done | active | todo | error.
  List<(String, String)> get _steps {
    final s = _s;
    final stage = s.stage;
    final ok = s.status == 'successful';
    final failed = s.status == 'failed' || s.status == 'reversed';
    // Échec pendant la collecte (avant tout versement) : c'est l'étape « payeur » qui a échoué.
    final failedAtSource = failed && !_payoutSeen && (_viaPhone || _viaCard);

    String sourceState(String waitingStage) {
      if (stage == waitingStage) return failed ? 'error' : 'active';
      return failedAtSource ? 'error' : 'done';
    }

    final steps = <(String, String)>[('Demande envoyée', 'done')];
    if (_viaCard) steps.add(('Paiement par carte', sourceState('awaiting_card')));
    if (_viaPhone && !_viaCard) steps.add(('Validation sur votre téléphone', sourceState('awaiting_source')));

    if (stage == 'awaiting_refund') {
      steps
        ..add(('Versement au bénéficiaire', 'error'))
        ..add(('Remboursement en cours', 'active'));
      return steps;
    }

    final payoutState = ok
        ? 'done'
        : failed
            ? (failedAtSource ? 'todo' : 'error')
            : (stage == 'awaiting_destination' || (!_viaPhone && !_viaCard))
                ? 'active'
                : 'todo';
    steps.add(('Versement au bénéficiaire', payoutState));
    steps.add((
      s.status == 'reversed' ? 'Remboursé' : (failed ? 'Échec' : 'Terminé'),
      ok || s.status == 'reversed' ? 'done' : (failed ? 'error' : 'todo'),
    ));
    return steps;
  }

  @override
  Widget build(BuildContext context) {
    final s = _s;
    final (IconData icon, Color color, String headline) = switch (s.status) {
      'successful' => (Icons.check_circle_rounded, FpColors.success, 'Opération réussie'),
      'reversed' => (Icons.undo_rounded, Colors.orange, 'Remboursé'),
      'failed' => (Icons.cancel_rounded, FpColors.danger, 'Opération échouée'),
      _ => s.awaitingCard
          ? (Icons.credit_card_rounded, FpColors.navy, 'Paiement par carte en attente')
          : s.awaitingUssd
              ? (Icons.phonelink_ring_rounded, FpColors.navy, 'Validez sur votre téléphone')
              : (Icons.sync_rounded, FpColors.navy, 'Traitement en cours'),
    };
    final long = s.isPending && _clock.elapsed >= const Duration(minutes: 3);

    return PopScope(
      canPop: !s.isPending || _clock.elapsed.inSeconds >= 10,
      child: Scaffold(
        appBar: AppBar(title: Text(tr(widget.title)), automaticallyImplyLeading: !s.isPending),
        body: SafeArea(
          child: ListView(
            padding: EdgeInsets.fromLTRB(24, 20, 24, 24),
            children: [
              Center(
                child: s.isPending
                    ? SizedBox(
                        width: 104,
                        height: 104,
                        child: Stack(alignment: Alignment.center, children: [
                          SizedBox(width: 104, height: 104, child: CircularProgressIndicator(strokeWidth: 4, color: FpColors.orange)),
                          Icon(icon, size: 48, color: color),
                        ]),
                      )
                    : Icon(icon, size: 96, color: color),
              ),
              SizedBox(height: 18),
              Text(tr(headline), style: TextStyle(fontSize: 21, fontWeight: FontWeight.w800), textAlign: TextAlign.center),
              SizedBox(height: 6),
              Text(fpMoney(s.destinationAmount, s.destinationCurrency),
                  textAlign: TextAlign.center, style: TextStyle(fontSize: 30, fontWeight: FontWeight.w900, color: FpColors.navy)),
              if (s.isPending) ...[
                SizedBox(height: 6),
                Row(mainAxisAlignment: MainAxisAlignment.center, children: [
                  Icon(Icons.timer_outlined, size: 16, color: Colors.black45),
                  SizedBox(width: 4),
                  Text('Temps écoulé $_elapsed', style: TextStyle(color: Colors.black54)),
                ]),
              ],
              if (s.message.isNotEmpty) ...[
                SizedBox(height: 10),
                Text(s.message, textAlign: TextAlign.center, style: TextStyle(color: Colors.black54)),
              ],
              SizedBox(height: 18),
              _Steps(steps: _steps),
              if (s.awaitingCard) ...[
                SizedBox(height: 16),
                OutlinedButton.icon(
                  onPressed: () => openCheckout(s.checkoutUrl ?? widget.initial.checkoutUrl),
                  icon: Icon(Icons.lock_outline),
                  label: Text(tr('Ouvrir la page de paiement sécurisée')),
                ),
              ],
              if (s.awaitingUssd) ...[
                SizedBox(height: 16),
                _Hint(
                  _clock.elapsed.inSeconds < 40
                      ? 'Une demande de paiement arrive sur ce téléphone : tapez votre code secret mobile money pour confirmer.'
                      : "Rien n'est apparu ? Ouvrez le menu mobile money de votre opérateur (USSD ou application) et validez le paiement en attente. "
                          "La demande reste valable quelques minutes.",
                ),
              ],
              if (long) ...[
                SizedBox(height: 12),
                _Hint(
                  "L'opérateur n'a pas encore confirmé. Vous pouvez quitter cet écran : vous serez notifié dès la confirmation "
                  'et aucune somme ne sera débitée deux fois.',
                  tone: Color(0xFF1E3A8A),
                  background: Color(0xFFE6EDFB),
                ),
              ],
              SizedBox(height: 22),
              if (!s.isPending) ...[
                FpReceiptButtons(transactionId: s.id, reference: s.reference),
                SizedBox(height: 10),
              ],
              Text('Réf. ${s.reference}', textAlign: TextAlign.center, style: TextStyle(color: Colors.grey, fontSize: 12)),
              if (s.fee > 0)
                Text('Frais : ${fpMoney(s.fee, s.currency)}', textAlign: TextAlign.center, style: TextStyle(color: Colors.grey, fontSize: 12)),
              SizedBox(height: 14),
              ElevatedButton(
                onPressed: () => Navigator.of(context).popUntil((r) => r.isFirst),
                child: Text(s.isPending ? 'Continuer en arrière-plan' : 'Terminé'),
              ),
              if (s.isPending && _gaveUp)
                TextButton(onPressed: () => setState(_start), child: Text(tr('Vérifier à nouveau'))),
            ],
          ),
        ),
      ),
    );
  }
}

class _Steps extends StatelessWidget {
  final List<(String, String)> steps;
  const _Steps({required this.steps});

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: EdgeInsets.fromLTRB(14, 12, 14, 12),
      decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(16), border: Border.all(color: FpColors.line)),
      child: Column(
        children: [
          for (var i = 0; i < steps.length; i++)
            Padding(
              padding: EdgeInsets.only(bottom: i == steps.length - 1 ? 0 : 10),
              child: Row(children: [
                _dot(steps[i].$2),
                SizedBox(width: 12),
                Expanded(
                  child: Text(
                    tr(steps[i].$1),
                    style: TextStyle(
                      fontWeight: steps[i].$2 == 'active' ? FontWeight.w700 : FontWeight.w500,
                      color: steps[i].$2 == 'todo' ? Colors.black38 : FpColors.ink,
                    ),
                  ),
                ),
                if (steps[i].$2 == 'active') Text(tr('en cours'), style: TextStyle(fontSize: 12, color: FpColors.orange)),
              ]),
            ),
        ],
      ),
    );
  }

  Widget _dot(String state) => switch (state) {
        'done' => Icon(Icons.check_circle_rounded, color: FpColors.success, size: 22),
        'error' => Icon(Icons.cancel_rounded, color: FpColors.danger, size: 22),
        'active' => SizedBox(width: 22, height: 22, child: Padding(padding: EdgeInsets.all(3), child: CircularProgressIndicator(strokeWidth: 2.5, color: FpColors.orange))),
        _ => Icon(Icons.radio_button_unchecked, color: Colors.black26, size: 22),
      };
}

class _Hint extends StatelessWidget {
  final String text;
  final Color tone;
  final Color background;
  const _Hint(this.text, {this.tone = const Color(0xFFA16207), this.background = const Color(0xFFFFF7E0)});

  @override
  Widget build(BuildContext context) => Container(
        padding: EdgeInsets.all(14),
        decoration: BoxDecoration(color: background, borderRadius: BorderRadius.circular(14)),
        child: Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Icon(Icons.info_outline, color: tone),
          SizedBox(width: 10),
          Expanded(child: Text(tr(text), style: TextStyle(fontSize: 13, color: tone))),
        ]),
      );
}
