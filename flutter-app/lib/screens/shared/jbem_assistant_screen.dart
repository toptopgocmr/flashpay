import 'package:flutter/material.dart';
import 'package:provider/provider.dart';
import '../../config/theme.dart';
import '../../models/user.dart';
import '../../providers/session_provider.dart';
import '../../services/features_service.dart';
import '../../services/payment_service.dart';
import '../../widgets/fp_design.dart';
import '../client/client_flows.dart';
import '../client/money_request_screen.dart';
import '../client/transaction_history_screen.dart';
import 'kyc_screen.dart';
import 'security_screen.dart';
import 'support_screen.dart';
import '../../l10n/l10n.dart';

/// Message de la conversation avec JBEM.
class _Msg {
  final bool bot;
  final String text;
  final List<(String, Widget Function())> actions;
  const _Msg(this.bot, this.text, [this.actions = const []]);
}

/// Assistant JBEM : répond aux questions courantes à partir de la base de
/// connaissances FlashPay (/support/faq) et des données du compte (solde,
/// plafonds), propose des raccourcis vers les parcours, et oriente vers le
/// support humain quand il ne sait pas répondre.
class JbemAssistantScreen extends StatefulWidget {
  const JbemAssistantScreen({super.key});

  @override
  State<JbemAssistantScreen> createState() => _JbemAssistantScreenState();
}

class _JbemAssistantScreenState extends State<JbemAssistantScreen> {
  final _input = TextEditingController();
  final _scroll = ScrollController();
  final List<_Msg> _msgs = [];
  List<Map> _faq = [];
  bool _typing = false;

  static const _suggestions = ['Mon solde', 'Envoyer de l\'argent', 'Retirer', 'Quels sont les frais ?', 'Mes plafonds', 'J\'ai oublié mon PIN'];

  @override
  void initState() {
    super.initState();
    final name = context.read<SessionProvider>().user?.fullName.split(' ').first ?? '';
    _msgs.add(_Msg(true, 'Bonjour${name.isNotEmpty ? ' $name' : ''} 👋 Je suis JBEM, votre assistant FlashPay. Posez-moi une question ou choisissez un sujet ci-dessous.'));
    FeaturesService().faq().then((d) {
      if (mounted) setState(() => _faq = ((d['faq'] ?? []) as List).cast<Map>());
    }).catchError((_) {});
  }

  @override
  void dispose() {
    _input.dispose();
    _scroll.dispose();
    super.dispose();
  }

  String _norm(String s) => s
      .toLowerCase()
      .replaceAll(RegExp('[éèêë]'), 'e')
      .replaceAll(RegExp('[àâä]'), 'a')
      .replaceAll(RegExp('[îï]'), 'i')
      .replaceAll(RegExp('[ôö]'), 'o')
      .replaceAll(RegExp('[ùûü]'), 'u')
      .replaceAll('ç', 'c');

  bool _has(String q, List<String> words) => words.any((w) => q.contains(w));

  _Msg _answer(String raw) {
    final q = _norm(raw);
    final user = context.read<SessionProvider>().user;
    final isClient = context.read<SessionProvider>().activeProfile == FpProfile.client;

    if (_has(q, ['solde', 'combien j', 'balance'])) {
      final w = user?.wallet;
      return _Msg(true, w == null ? 'Je ne trouve pas de wallet sur ce compte.' : 'Votre solde disponible est de ${fpMoney(w.balance, w.currency)}.',
          [('Voir l\'historique', () => TransactionHistoryScreen())]);
    }
    if (isClient && _has(q, ['demand', 'reclam', 'rembours', 'me doit', 'doit de l'])) {
      return _Msg(true, 'Demandez de l\'argent à un ami : scannez son QR « Code QR », approchez son téléphone (NFC) ou saisissez son numéro. Il paie en un geste avec son PIN.',
          [('Demander de l\'argent', () => RequestMoneyScreen()), ('Mes demandes', () => MoneyRequestsScreen())]);
    }
    if (isClient && _has(q, ['envoy', 'transfer', 'transfert'])) {
      return _Msg(true, 'Vous pouvez envoyer vers un wallet FlashPay, un numéro mobile money (MTN, Airtel…) ou un compte bancaire. Les frais s\'affichent avant validation.',
          [('Envoyer de l\'argent', () => SendFlowScreen())]);
    }
    if (isClient && _has(q, ['retir', 'retrait', 'cash', 'espece'])) {
      return _Msg(true, 'Retirez en espèces chez un agent FlashPay (code de retrait), vers votre mobile money ou vers votre compte bancaire.',
          [('Faire un retrait', () => WithdrawFlowScreen())]);
    }
    if (isClient && _has(q, ['recharg', 'depot', 'deposer', 'alimenter'])) {
      return _Msg(true, 'Rechargez depuis votre mobile money, une carte bancaire, ou en espèces chez un agent (il scanne votre code de dépôt).',
          [('Recharger mon wallet', () => RechargeWalletScreen())]);
    }
    if (isClient && _has(q, ['payer', 'paiement', 'marchand', 'boutique'])) {
      return _Msg(true, 'Scannez le QR du marchand, approchez son terminal NFC ou saisissez son code marchand. Le paiement marchand est gratuit pour vous.',
          [('Payer un marchand', () => PayFlowScreen())]);
    }
    if (_has(q, ['plafond', 'limite', 'kyc', 'identite', 'verifi'])) {
      return _Msg(true, 'Vos plafonds dépendent de votre niveau de vérification (palier ${user?.kycTier ?? 0} sur 2). Envoyez vos pièces pour les relever.',
          [('Identité & plafonds', () => KycScreen())]);
    }
    if (_has(q, ['pin', 'code secret', 'mot de passe', 'securite', 'appareil'])) {
      final faq = _fromFaq(q);
      return _Msg(true, faq ?? 'Vous pouvez changer votre code PIN et gérer vos appareils connectés dans Sécurité.',
          [('Sécurité & PIN', () => SecurityScreen())]);
    }
    final faq = _fromFaq(q);
    if (faq != null) return _Msg(true, faq);
    return _Msg(true, 'Je n\'ai pas trouvé de réponse précise. Un conseiller FlashPay peut vous aider : ouvrez une demande, réponse en général sous 24 h.',
        [('Contacter le support', () => SupportScreen())]);
  }

  /// Meilleure réponse de la FAQ (mots communs entre la question et l'entrée).
  String? _fromFaq(String q) {
    final words = q.split(RegExp(r'[^a-z0-9]+')).where((w) => w.length > 3).toSet();
    if (words.isEmpty) return null;
    Map? best;
    var bestScore = 0;
    for (final f in _faq) {
      final text = _norm('${f['q']} ${f['a']}');
      final score = words.where(text.contains).length;
      if (score > bestScore) {
        best = f;
        bestScore = score;
      }
    }
    return bestScore >= 1 && best != null ? '${best['a']}' : null;
  }

  Future<void> _send([String? preset]) async {
    final text = (preset ?? _input.text).trim();
    if (text.isEmpty) return;
    _input.clear();
    setState(() {
      _msgs.add(_Msg(false, text));
      _typing = true;
    });
    _toBottom();
    await Future.delayed(Duration(milliseconds: 550));
    if (!mounted) return;
    setState(() {
      _typing = false;
      _msgs.add(_answer(text));
    });
    _toBottom();
  }

  void _toBottom() => WidgetsBinding.instance.addPostFrameCallback((_) {
        if (_scroll.hasClients) _scroll.animateTo(_scroll.position.maxScrollExtent, duration: Duration(milliseconds: 250), curve: Curves.easeOut);
      });

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        titleSpacing: 0,
        title: Row(children: [
          FpPastille(Icons.smart_toy_outlined, size: 38),
          SizedBox(width: 10),
          Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
            Text(tr('JBEM'), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w700)),
            Text(tr('Assistant FlashPay'), style: TextStyle(fontSize: 12, color: FpColors.muted)),
          ]),
        ]),
      ),
      body: Column(children: [
        Expanded(
          child: ListView.builder(
            controller: _scroll,
            padding: EdgeInsets.fromLTRB(14, 8, 14, 8),
            itemCount: _msgs.length + (_typing ? 1 : 0),
            itemBuilder: (_, i) {
              if (i == _msgs.length) return _Bubble(bot: true, text: '…');
              final m = _msgs[i];
              return Column(crossAxisAlignment: m.bot ? CrossAxisAlignment.start : CrossAxisAlignment.end, children: [
                _Bubble(bot: m.bot, text: m.text),
                if (m.actions.isNotEmpty)
                  Padding(
                    padding: EdgeInsets.only(bottom: 8),
                    child: Wrap(spacing: 8, children: [
                      for (final a in m.actions)
                        ActionChip(
                          avatar: Icon(Icons.arrow_forward_rounded, size: 16, color: FpColors.red),
                          label: Text(a.$1),
                          backgroundColor: FpColors.rose,
                          onPressed: () => Navigator.push(context, MaterialPageRoute(builder: (_) => a.$2())),
                        ),
                    ]),
                  ),
              ]);
            },
          ),
        ),
        SizedBox(
          height: 46,
          child: ListView(
            scrollDirection: Axis.horizontal,
            padding: EdgeInsets.symmetric(horizontal: 12),
            children: [
              for (final s in _suggestions)
                Padding(
                  padding: EdgeInsets.only(right: 8),
                  child: ActionChip(label: Text(s), backgroundColor: FpColors.soft, onPressed: () => _send(s)),
                ),
            ],
          ),
        ),
        SafeArea(
          top: false,
          child: Padding(
            padding: EdgeInsets.fromLTRB(12, 6, 8, 10),
            child: Row(children: [
              Expanded(
                child: TextField(
                  controller: _input,
                  textInputAction: TextInputAction.send,
                  onSubmitted: (_) => _send(),
                  decoration: InputDecoration(
                    hintText: tr('Écrire à JBEM…'),
                    contentPadding: EdgeInsets.symmetric(horizontal: 18, vertical: 12),
                    border: OutlineInputBorder(borderRadius: BorderRadius.circular(28), borderSide: BorderSide(color: FpColors.line)),
                    enabledBorder: OutlineInputBorder(borderRadius: BorderRadius.circular(28), borderSide: BorderSide(color: FpColors.line)),
                  ),
                ),
              ),
              SizedBox(width: 6),
              IconButton.filled(
                style: IconButton.styleFrom(backgroundColor: FpColors.red, foregroundColor: Colors.white),
                onPressed: () => _send(),
                icon: Icon(Icons.send_rounded),
              ),
            ]),
          ),
        ),
      ]),
    );
  }
}

class _Bubble extends StatelessWidget {
  final bool bot;
  final String text;
  const _Bubble({required this.bot, required this.text});

  @override
  Widget build(BuildContext context) => Container(
        margin: EdgeInsets.symmetric(vertical: 4),
        padding: EdgeInsets.symmetric(horizontal: 14, vertical: 11),
        constraints: BoxConstraints(maxWidth: MediaQuery.of(context).size.width * .78),
        decoration: BoxDecoration(
          color: bot ? Colors.white : FpColors.navy,
          border: bot ? Border.all(color: FpColors.line) : null,
          borderRadius: BorderRadius.only(
            topLeft: Radius.circular(18),
            topRight: Radius.circular(18),
            bottomLeft: Radius.circular(bot ? 4 : 18),
            bottomRight: Radius.circular(bot ? 18 : 4),
          ),
        ),
        child: Text(tr(text), style: TextStyle(color: bot ? FpColors.ink : Colors.white, fontSize: 15, height: 1.35)),
      );
}
