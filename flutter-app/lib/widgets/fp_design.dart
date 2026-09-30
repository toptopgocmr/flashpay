import 'dart:typed_data';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import '../config/theme.dart';
import '../l10n/l10n.dart';

/// Kit d'interface FlashPay v2 — composants communs aux quatre espaces
/// (client, agent, marchand, caissier) repris des maquettes validées.

/// Teinte d'une pastille : rose (icône rouge) ou bleu pâle (icône marine).
enum FpTone { red, blue }

FpTone fpToneAt(int index) => index.isEven ? FpTone.red : FpTone.blue;

/// Pastille ronde avec icône.
class FpPastille extends StatelessWidget {
  final IconData icon;
  final FpTone tone;
  final double size;
  const FpPastille(this.icon, {super.key, this.tone = FpTone.red, this.size = 56});

  @override
  Widget build(BuildContext context) => Container(
        width: size,
        height: size,
        decoration: BoxDecoration(color: tone == FpTone.red ? FpColors.rose : FpColors.soft, shape: BoxShape.circle),
        child: Icon(icon, size: size * .44, color: tone == FpTone.red ? FpColors.red : FpColors.navy),
      );
}

/// Action ronde + libellé (grille « Principal » de l'accueil client).
class FpCircleAction extends StatelessWidget {
  final IconData icon;
  final String label;
  final FpTone tone;
  final VoidCallback onTap;
  final int badge;
  const FpCircleAction({super.key, required this.icon, required this.label, required this.onTap, this.tone = FpTone.red, this.badge = 0});

  @override
  Widget build(BuildContext context) => InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16),
        child: Padding(
          padding: EdgeInsets.symmetric(vertical: 6),
          child: Column(mainAxisSize: MainAxisSize.min, children: [
            Badge(isLabelVisible: badge > 0, label: Text('$badge'), backgroundColor: FpColors.red, child: FpPastille(icon, tone: tone)),
            SizedBox(height: 8),
            Text(tr(label), textAlign: TextAlign.center, maxLines: 2, style: TextStyle(fontSize: 13, fontWeight: FontWeight.w500, color: FpColors.ink, height: 1.15)),
          ]),
        ),
      );
}

/// Grille de 4 colonnes d'actions rondes.
class FpCircleGrid extends StatelessWidget {
  final List<Widget> children;
  final int columns;
  const FpCircleGrid({super.key, required this.children, this.columns = 4});

  @override
  Widget build(BuildContext context) => LayoutBuilder(builder: (context, c) {
        final w = c.maxWidth / columns;
        return Wrap(
          runSpacing: 10,
          children: children.map((e) => SizedBox(width: w, child: e)).toList(),
        );
      });
}

/// Tuile carrée (grille 2 colonnes des espaces agent / marchand / caissier).
class FpTile extends StatelessWidget {
  final IconData icon;
  final String label;
  final FpTone tone;
  final VoidCallback onTap;
  final String? badge;
  const FpTile({super.key, required this.icon, required this.label, required this.onTap, this.tone = FpTone.red, this.badge});

  @override
  Widget build(BuildContext context) => Material(
        color: Colors.white,
        borderRadius: BorderRadius.circular(22),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(22),
          child: Container(
            padding: EdgeInsets.symmetric(vertical: 20, horizontal: 10),
            decoration: BoxDecoration(borderRadius: BorderRadius.circular(22), border: Border.all(color: FpColors.line)),
            child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
              Badge(
                isLabelVisible: badge != null,
                label: Text(badge ?? ''),
                backgroundColor: FpColors.red,
                child: FpPastille(icon, tone: tone, size: 58),
              ),
              SizedBox(height: 12),
              Text(tr(label), textAlign: TextAlign.center, maxLines: 2, style: TextStyle(fontSize: 14.5, fontWeight: FontWeight.w500, color: FpColors.ink, height: 1.2)),
            ]),
          ),
        ),
      );
}

/// Grille 2 colonnes de [FpTile] (couleurs alternées automatiquement si
/// les tuiles sont construites avec [fpToneAt]).
class FpTileGrid extends StatelessWidget {
  final List<Widget> children;
  const FpTileGrid({super.key, required this.children});

  @override
  Widget build(BuildContext context) => GridView.count(
        crossAxisCount: 2,
        shrinkWrap: true,
        physics: NeverScrollableScrollPhysics(),
        mainAxisSpacing: 14,
        crossAxisSpacing: 14,
        childAspectRatio: 1.18,
        children: children,
      );
}

/// Chiffre secondaire d'un en-tête (ex. « Commissions du jour »).
class FpHeaderStat {
  final String label;
  final String value;
  const FpHeaderStat(this.label, this.value);
}

/// En-tête bleu marine d'un espace pro : titre + cloche, libellé du solde,
/// montant en gros, chiffres secondaires.
class FpRoleHeader extends StatefulWidget {
  final String title;
  final String? subtitle;
  final String balanceLabel;
  final String balance;
  final List<FpHeaderStat> stats;
  final int notifications;
  final VoidCallback? onBell;
  final List<Widget> actions;
  const FpRoleHeader({
    super.key,
    required this.title,
    required this.balanceLabel,
    required this.balance,
    this.subtitle,
    this.stats = const [],
    this.notifications = 0,
    this.onBell,
    this.actions = const [],
  });

  @override
  State<FpRoleHeader> createState() => _FpRoleHeaderState();
}

class _FpRoleHeaderState extends State<FpRoleHeader> {
  bool _hidden = false;

  @override
  Widget build(BuildContext context) {
    const white70 = TextStyle(color: Color(0xCCFFFFFF), fontSize: 14.5);
    return Container(
      width: double.infinity,
      padding: EdgeInsets.fromLTRB(22, MediaQuery.of(context).padding.top + 18, 14, 26),
      decoration: BoxDecoration(
        color: FpColors.navy,
        borderRadius: BorderRadius.vertical(bottom: Radius.circular(26)),
      ),
      child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
        Row(children: [
          Expanded(
            child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
              Text(tr(widget.title), style: TextStyle(color: Colors.white, fontSize: 21, fontWeight: FontWeight.w600)),
              if (widget.subtitle != null && widget.subtitle!.isNotEmpty)
                Padding(padding: EdgeInsets.only(top: 2), child: Text(widget.subtitle!, style: TextStyle(color: Color(0xB3FFFFFF), fontSize: 12.5))),
            ]),
          ),
          ...widget.actions,
          if (widget.onBell != null)
            IconButton(
              onPressed: widget.onBell,
              icon: Badge(
                isLabelVisible: widget.notifications > 0,
                label: Text('${widget.notifications}'),
                backgroundColor: FpColors.red,
                child: Icon(Icons.notifications_none_rounded, color: Colors.white, size: 27),
              ),
            ),
        ]),
        SizedBox(height: 16),
        Row(children: [
          Text(widget.balanceLabel, style: white70),
          SizedBox(width: 6),
          GestureDetector(
            onTap: () => setState(() => _hidden = !_hidden),
            child: Icon(_hidden ? Icons.visibility_off_outlined : Icons.visibility_outlined, color: Color(0x99FFFFFF), size: 18),
          ),
        ]),
        SizedBox(height: 4),
        FittedBox(
          fit: BoxFit.scaleDown,
          alignment: Alignment.centerLeft,
          child: Text(_hidden ? '••••••' : widget.balance,
              style: TextStyle(color: Colors.white, fontSize: 36, fontWeight: FontWeight.w600, letterSpacing: .2)),
        ),
        if (widget.stats.isNotEmpty) ...[
          SizedBox(height: 14),
          Wrap(
            spacing: 42,
            runSpacing: 8,
            children: widget.stats
                .map((s) => Column(crossAxisAlignment: CrossAxisAlignment.start, mainAxisSize: MainAxisSize.min, children: [
                      Text(tr(s.label), style: TextStyle(color: Color(0xCCFFFFFF), fontSize: 13.5)),
                      SizedBox(height: 2),
                      Text(_hidden ? '•••' : s.value, style: TextStyle(color: Colors.white, fontSize: 17, fontWeight: FontWeight.w600)),
                    ]))
                .toList(),
          ),
        ],
      ]),
    );
  }
}

/// Ligne « Support » en bas des espaces pro.
class FpSupportRow extends StatelessWidget {
  final String label;
  final VoidCallback onTap;
  final IconData icon;
  const FpSupportRow({super.key, required this.label, required this.onTap, this.icon = Icons.headset_mic_outlined});

  @override
  Widget build(BuildContext context) => Material(
        color: Colors.white,
        borderRadius: BorderRadius.circular(18),
        child: InkWell(
          onTap: onTap,
          borderRadius: BorderRadius.circular(18),
          child: Container(
            padding: EdgeInsets.symmetric(horizontal: 18, vertical: 18),
            decoration: BoxDecoration(borderRadius: BorderRadius.circular(18), border: Border.all(color: FpColors.line)),
            child: Row(children: [
              Icon(icon, color: FpColors.muted),
              SizedBox(width: 14),
              Expanded(child: Text(tr(label), style: TextStyle(fontSize: 15.5, color: FpColors.ink))),
              Icon(Icons.chevron_right_rounded, color: FpColors.muted),
            ]),
          ),
        ),
      );
}

/// Carte d'invitation (ex. « Associer une carte bancaire »).
class FpPromptCard extends StatelessWidget {
  final IconData icon;
  final FpTone tone;
  final String title;
  final String subtitle;
  final VoidCallback onTap;
  const FpPromptCard({super.key, required this.icon, required this.title, required this.subtitle, required this.onTap, this.tone = FpTone.blue});

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.only(bottom: 12),
        child: Material(
          color: Colors.white,
          borderRadius: BorderRadius.circular(20),
          child: InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(20),
            child: Container(
              padding: EdgeInsets.all(16),
              decoration: BoxDecoration(borderRadius: BorderRadius.circular(20), border: Border.all(color: FpColors.line)),
              child: Row(children: [
                FpPastille(icon, tone: tone, size: 52),
                SizedBox(width: 14),
                Expanded(
                  child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
                    Text(tr(title), style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600, color: FpColors.ink)),
                    SizedBox(height: 2),
                    Text(tr(subtitle), style: TextStyle(fontSize: 13.5, color: FpColors.muted)),
                  ]),
                ),
                Icon(Icons.chevron_right_rounded, color: FpColors.muted),
              ]),
            ),
          ),
        ),
      );
}

/// Bandeau notification rose (« 1 nouvelle notification »).
class FpNotifBanner extends StatelessWidget {
  final String text;
  final VoidCallback onTap;
  const FpNotifBanner(this.text, {super.key, required this.onTap});

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.only(bottom: 14),
        child: Material(
          color: FpColors.rose,
          borderRadius: BorderRadius.circular(14),
          child: InkWell(
            onTap: onTap,
            borderRadius: BorderRadius.circular(14),
            child: Padding(
              padding: EdgeInsets.symmetric(horizontal: 16, vertical: 14),
              child: Row(children: [
                Icon(Icons.notifications_none_rounded, color: FpColors.red),
                SizedBox(width: 12),
                Expanded(child: Text(tr(text), style: TextStyle(color: FpColors.red, fontSize: 15.5, fontWeight: FontWeight.w500))),
                Icon(Icons.chevron_right_rounded, color: FpColors.red),
              ]),
            ),
          ),
        ),
      );
}

// ---------------------------------------------------------------------------
// Parcours (Recharger / Envoyer / Retrait / Payer)
// ---------------------------------------------------------------------------

/// Nœud d'un type d'opération : wallet, mobile money, banque, agent, marchand.
enum FpNode { wallet, mobile, bank, cash, merchant, card }

IconData fpNodeIcon(FpNode n) => switch (n) {
      FpNode.wallet => Icons.account_balance_wallet_outlined,
      FpNode.mobile => Icons.smartphone_rounded,
      FpNode.bank => Icons.account_balance_outlined,
      FpNode.cash => Icons.payments_outlined,
      FpNode.merchant => Icons.storefront_outlined,
      FpNode.card => Icons.credit_card_rounded,
    };

Color fpNodeColor(FpNode n) => n == FpNode.wallet ? Color(0xFF3056D3) : FpColors.red;

/// Une option de type « A → B » (ex. Wallet vers mobile money).
class FpFlowType<T> {
  final T value;
  final FpNode from;
  final FpNode to;
  final String label;
  const FpFlowType(this.value, this.from, this.to, this.label);
}

/// Liste d'options « A → B » dans une carte, une seule sélectionnable.
class FpFlowTypeList<T> extends StatelessWidget {
  final List<FpFlowType<T>> options;
  final T? selected;
  final ValueChanged<T> onSelect;
  const FpFlowTypeList({super.key, required this.options, required this.selected, required this.onSelect});

  @override
  Widget build(BuildContext context) => Container(
        padding: EdgeInsets.symmetric(vertical: 6),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(22), border: Border.all(color: FpColors.line)),
        child: Column(
          children: options.map((o) {
            final sel = o.value == selected;
            return InkWell(
              onTap: () => onSelect(o.value),
              borderRadius: BorderRadius.circular(16),
              child: Container(
                margin: EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                padding: EdgeInsets.symmetric(horizontal: 14, vertical: 15),
                decoration: BoxDecoration(color: sel ? FpColors.soft : Colors.transparent, borderRadius: BorderRadius.circular(16)),
                child: Row(children: [
                  Icon(fpNodeIcon(o.from), color: fpNodeColor(o.from), size: 24),
                  Padding(padding: EdgeInsets.symmetric(horizontal: 6), child: Icon(Icons.arrow_forward, size: 16, color: FpColors.muted)),
                  Icon(fpNodeIcon(o.to), color: fpNodeColor(o.to), size: 24),
                  SizedBox(width: 16),
                  Expanded(child: Text(tr(o.label), style: TextStyle(fontSize: 16, fontWeight: sel ? FontWeight.w700 : FontWeight.w500, color: FpColors.ink))),
                  if (sel) Icon(Icons.check_circle_rounded, color: FpColors.navy, size: 20),
                ]),
              ),
            );
          }).toList(),
        ),
      );
}

/// Méthode de désignation (NFC, Scanner, Code…).
class FpPickMethod<T> {
  final T value;
  final IconData icon;
  final String label;
  final FpTone tone;
  const FpPickMethod(this.value, this.icon, this.label, {this.tone = FpTone.red});
}

/// Rangée de cartes de méthodes (2 ou 3 colonnes), une seule sélectionnable.
class FpMethodRow<T> extends StatelessWidget {
  final List<FpPickMethod<T>> methods;
  final T? selected;
  final ValueChanged<T> onSelect;
  const FpMethodRow({super.key, required this.methods, required this.selected, required this.onSelect});

  @override
  Widget build(BuildContext context) => Row(
        children: [
          for (var i = 0; i < methods.length; i++) ...[
            if (i > 0) SizedBox(width: 12),
            Expanded(child: _methodCard(methods[i])),
          ],
        ],
      );

  Widget _methodCard(FpPickMethod<T> m) {
    final sel = m.value == selected;
    return Material(
      color: sel ? FpColors.soft : Colors.white,
      borderRadius: BorderRadius.circular(18),
      child: InkWell(
        onTap: () => onSelect(m.value),
        borderRadius: BorderRadius.circular(18),
        child: Container(
          height: 104,
          padding: EdgeInsets.symmetric(horizontal: 6),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(18),
            border: Border.all(color: sel ? FpColors.navy : FpColors.line, width: sel ? 1.6 : 1),
          ),
          child: Column(mainAxisAlignment: MainAxisAlignment.center, children: [
            Icon(m.icon, size: 30, color: m.tone == FpTone.red ? FpColors.red : Color(0xFF3056D3)),
            SizedBox(height: 10),
            Text(tr(m.label), textAlign: TextAlign.center, maxLines: 2, style: TextStyle(fontSize: 15, fontWeight: FontWeight.w500, color: FpColors.ink, height: 1.15)),
          ]),
        ),
      ),
    );
  }
}

/// Bouton plein pleine largeur. [red] = style « Continuer » des parcours,
/// sinon bleu marine (validation de formulaire).
class FpButton extends StatelessWidget {
  final String label;
  final VoidCallback? onPressed;
  final bool red;
  final bool loading;
  const FpButton(this.label, {super.key, this.onPressed, this.red = false, this.loading = false});

  @override
  Widget build(BuildContext context) => SizedBox(
        width: double.infinity,
        height: 56,
        child: ElevatedButton(
          style: ElevatedButton.styleFrom(
            backgroundColor: red ? FpColors.redSoft : FpColors.navy,
            disabledBackgroundColor: (red ? FpColors.redSoft : FpColors.navy).withOpacity(.4),
            shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(red ? 18 : 30)),
          ),
          onPressed: loading ? null : onPressed,
          child: loading
              ? SizedBox(height: 22, width: 22, child: CircularProgressIndicator(strokeWidth: 2.4, color: Colors.white))
              : Text(tr(label), style: TextStyle(fontSize: 17, fontWeight: FontWeight.w600)),
        ),
      );
}

/// Libellé de section des parcours (« Type de transfert »…).
class FpFlowLabel extends StatelessWidget {
  final String text;
  const FpFlowLabel(this.text, {super.key});

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.only(left: 4, top: 18, bottom: 10),
        child: Text(tr(text), style: TextStyle(fontSize: 15, color: FpColors.muted)),
      );
}

/// Onglets segmentés (Manuel / QR code / NFC).
class FpSegmented<T> extends StatelessWidget {
  final List<(T, String)> items;
  final T selected;
  final ValueChanged<T> onChanged;
  const FpSegmented({super.key, required this.items, required this.selected, required this.onChanged});

  @override
  Widget build(BuildContext context) => Container(
        padding: EdgeInsets.all(6),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(20), border: Border.all(color: FpColors.line)),
        child: Row(
          children: items.map((it) {
            final sel = it.$1 == selected;
            return Expanded(
              child: GestureDetector(
                onTap: () => onChanged(it.$1),
                child: AnimatedContainer(
                  duration: Duration(milliseconds: 180),
                  padding: EdgeInsets.symmetric(vertical: 13),
                  decoration: BoxDecoration(color: sel ? FpColors.navy : Colors.transparent, borderRadius: BorderRadius.circular(15)),
                  child: Text(it.$2,
                      textAlign: TextAlign.center,
                      style: TextStyle(fontSize: 15.5, fontWeight: FontWeight.w600, color: sel ? Colors.white : FpColors.muted)),
                ),
              ),
            );
          }).toList(),
        ),
      );
}

/// Barre de titre des parcours : flèche retour + titre.
class FpFlowScaffold extends StatelessWidget {
  final String title;
  final List<Widget> children;
  final Widget? bottom;
  const FpFlowScaffold({super.key, required this.title, required this.children, this.bottom});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(
          title: Text(tr(title), style: TextStyle(fontSize: 22, fontWeight: FontWeight.w600)),
          leading: IconButton(icon: Icon(Icons.arrow_back_rounded, size: 28), onPressed: () => Navigator.maybePop(context)),
        ),
        body: SafeArea(
          top: false,
          child: Column(children: [
            Expanded(child: ListView(padding: EdgeInsets.fromLTRB(18, 0, 18, 18), children: children)),
            if (bottom != null) Padding(padding: EdgeInsets.fromLTRB(18, 0, 18, 16), child: bottom),
          ]),
        ),
      );
}

// ---------------------------------------------------------------------------
// Formulaires (connexion / inscription)
// ---------------------------------------------------------------------------

/// Champ souligné : libellé au-dessus, préfixe facultatif (+242).
class FpLineField extends StatelessWidget {
  final String label;
  final TextEditingController controller;
  final String? hint;
  final String? prefix;
  final TextInputType? keyboardType;
  final ValueChanged<String>? onChanged;
  final bool obscure;
  final TextCapitalization capitalization;
  final bool readOnly;
  final VoidCallback? onTap;
  const FpLineField({
    super.key,
    required this.label,
    required this.controller,
    this.hint,
    this.prefix,
    this.keyboardType,
    this.onChanged,
    this.obscure = false,
    this.capitalization = TextCapitalization.none,
    this.readOnly = false,
    this.onTap,
  });

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.only(bottom: 22),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr(label), style: TextStyle(fontSize: 14.5, color: FpColors.ink)),
          TextField(
            controller: controller,
            keyboardType: keyboardType,
            obscureText: obscure,
            onChanged: onChanged,
            readOnly: readOnly,
            onTap: onTap,
            textCapitalization: capitalization,
            style: TextStyle(fontSize: 18, color: FpColors.ink),
            decoration: InputDecoration(
              hintText: hint,
              hintStyle: TextStyle(color: Color(0xFF9CA3AF), fontSize: 18),
              prefixIcon: prefix == null
                  ? null
                  : Padding(
                      padding: EdgeInsets.only(right: 12, top: 12, bottom: 12),
                      child: Text(prefix!, style: TextStyle(fontSize: 18, color: FpColors.ink)),
                    ),
              prefixIconConstraints: BoxConstraints(minWidth: 0, minHeight: 0),
              filled: false,
              isDense: false,
              contentPadding: EdgeInsets.symmetric(vertical: 12),
              border: UnderlineInputBorder(borderSide: BorderSide(color: FpColors.line, width: 1.4)),
              enabledBorder: UnderlineInputBorder(borderSide: BorderSide(color: FpColors.line, width: 1.4)),
              focusedBorder: UnderlineInputBorder(borderSide: BorderSide(color: FpColors.navy, width: 1.8)),
            ),
          ),
        ]),
      );
}

/// Liste déroulante soulignée (ex. « Secteur d'activité »).
class FpLineDropdown extends StatelessWidget {
  final String label;
  final String? value;
  final List<String> items;
  final ValueChanged<String?> onChanged;
  const FpLineDropdown({super.key, required this.label, required this.value, required this.items, required this.onChanged});

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.only(bottom: 22),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text(tr(label), style: TextStyle(fontSize: 14.5, color: FpColors.ink)),
          DropdownButtonFormField<String>(
            value: value,
            isExpanded: true,
            icon: Icon(Icons.keyboard_arrow_down_rounded, color: FpColors.ink),
            hint: Text(tr('Sélectionner'), style: TextStyle(color: Color(0xFF9CA3AF), fontSize: 18)),
            style: TextStyle(fontSize: 18, color: FpColors.ink),
            items: items.map((e) => DropdownMenuItem(value: e, child: Text(e))).toList(),
            onChanged: onChanged,
            decoration: InputDecoration(
              filled: false,
              contentPadding: EdgeInsets.symmetric(vertical: 10),
              border: UnderlineInputBorder(borderSide: BorderSide(color: FpColors.line, width: 1.4)),
              enabledBorder: UnderlineInputBorder(borderSide: BorderSide(color: FpColors.line, width: 1.4)),
              focusedBorder: UnderlineInputBorder(borderSide: BorderSide(color: FpColors.navy, width: 1.8)),
            ),
          ),
        ]),
      );
}

/// Code secret en cases (4 chiffres par défaut) : un champ invisible reçoit
/// la saisie, les cases affichent des points ; cases remplies en rouge.
class FpCodeBoxes extends StatefulWidget {
  final int length;
  final TextEditingController controller;
  final ValueChanged<String>? onCompleted;
  final bool autofocus;
  const FpCodeBoxes({super.key, required this.controller, this.length = 4, this.onCompleted, this.autofocus = false});

  @override
  State<FpCodeBoxes> createState() => _FpCodeBoxesState();
}

class _FpCodeBoxesState extends State<FpCodeBoxes> {
  final _focus = FocusNode();

  @override
  void initState() {
    super.initState();
    _focus.addListener(_refresh);
    widget.controller.addListener(_refresh);
  }

  void _refresh() {
    if (mounted) setState(() {});
  }

  @override
  void dispose() {
    widget.controller.removeListener(_refresh);
    _focus.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final text = widget.controller.text;
    return Stack(children: [
        Row(
          children: List.generate(widget.length, (i) {
            final filled = i < text.length;
            final current = _focus.hasFocus && i == text.length;
            return Container(
              width: 58,
              height: 66,
              margin: EdgeInsets.only(right: 12),
              decoration: BoxDecoration(
                color: Colors.white,
                borderRadius: BorderRadius.circular(14),
                border: Border.all(color: filled ? FpColors.red : (current ? FpColors.navy : FpColors.line), width: filled || current ? 1.8 : 1.4),
              ),
              alignment: Alignment.center,
              child: filled ? Container(width: 11, height: 11, decoration: BoxDecoration(color: FpColors.ink, shape: BoxShape.circle)) : null,
            );
          }),
        ),
        // Champ réel, invisible, posé sur les cases : un appui ouvre le clavier
        Positioned.fill(
          child: Opacity(
            opacity: 0,
            child: TextField(
              controller: widget.controller,
              focusNode: _focus,
              autofocus: widget.autofocus,
              keyboardType: TextInputType.number,
              maxLength: widget.length,
              showCursor: false,
              enableInteractiveSelection: false,
              inputFormatters: [FilteringTextInputFormatter.digitsOnly],
              decoration: InputDecoration(counterText: '', border: InputBorder.none, enabledBorder: InputBorder.none, focusedBorder: InputBorder.none, filled: false),
              onChanged: (v) {
                setState(() {});
                if (v.length == widget.length) widget.onCompleted?.call(v);
              },
            ),
          ),
        ),
      ]);
  }
}

/// « Étape 1 sur 2 » + barre de progression rouge.
class FpStepHeader extends StatelessWidget {
  final int step;
  final int total;
  const FpStepHeader({super.key, required this.step, this.total = 2});

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.only(bottom: 22),
        child: Row(children: [
          Text('Étape $step sur $total', style: TextStyle(color: FpColors.red, fontSize: 14.5, fontWeight: FontWeight.w500)),
          SizedBox(width: 16),
          Expanded(
            child: ClipRRect(
              borderRadius: BorderRadius.circular(4),
              child: LinearProgressIndicator(value: step / total, minHeight: 4, color: FpColors.red, backgroundColor: FpColors.line),
            ),
          ),
        ]),
      );
}

/// Carte blanche centrale des écrans d'accès.
class FpAuthCard extends StatelessWidget {
  final List<Widget> children;
  final CrossAxisAlignment align;
  const FpAuthCard({super.key, required this.children, this.align = CrossAxisAlignment.stretch});

  @override
  Widget build(BuildContext context) => Center(
        child: ConstrainedBox(
          constraints: BoxConstraints(maxWidth: 480),
          child: Container(
            margin: EdgeInsets.fromLTRB(16, 8, 16, 16),
            padding: EdgeInsets.fromLTRB(24, 28, 24, 24),
            decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(28), border: Border.all(color: FpColors.line)),
            child: Column(crossAxisAlignment: align, children: children),
          ),
        ),
      );
}

/// Logo carré bleu marine avec l'éclair (écrans d'accès).
class FpBoltLogo extends StatelessWidget {
  final double size;
  const FpBoltLogo({super.key, this.size = 72});

  @override
  Widget build(BuildContext context) => Container(
        width: size,
        height: size,
        decoration: BoxDecoration(color: FpColors.navy, borderRadius: BorderRadius.circular(size * .26)),
        child: Icon(Icons.bolt_outlined, color: Colors.white, size: size * .55),
      );
}

// ---------------------------------------------------------------------------
// Téléversement de pièces (KYC)
// ---------------------------------------------------------------------------

class _DashedPainter extends CustomPainter {
  final double radius;
  final bool circle;
  final Color color;
  _DashedPainter({required this.radius, this.circle = false, required this.color});

  @override
  void paint(Canvas canvas, Size size) {
    final paint = Paint()
      ..color = color
      ..strokeWidth = 1.6
      ..style = PaintingStyle.stroke;
    final rect = Offset.zero & size;
    final path = circle ? (Path()..addOval(rect.deflate(1))) : (Path()..addRRect(RRect.fromRectAndRadius(rect.deflate(1), Radius.circular(radius))));
    for (final metric in path.computeMetrics()) {
      double d = 0;
      while (d < metric.length) {
        canvas.drawPath(metric.extractPath(d, d + 6), paint);
        d += 11;
      }
    }
  }

  @override
  bool shouldRepaint(covariant _DashedPainter old) => old.color != color;
}

/// Cadre pointillé de dépôt de document. [done] = pièce jointe.
class FpUploadBox extends StatelessWidget {
  final IconData icon;
  final String label;
  final VoidCallback onTap;
  final bool done;
  final bool compact; // ligne « Ajouter le document  ⤒ »
  /// Aperçu de l'image choisie (affiché à la place de l'icône).
  final Uint8List? preview;
  const FpUploadBox({super.key, required this.icon, required this.label, required this.onTap, this.done = false, this.compact = false, this.preview});

  @override
  Widget build(BuildContext context) {
    final color = done ? FpColors.success : Color(0xFF9CA3AF);
    final img = preview;
    if (img != null && !compact) {
      return InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16),
        child: CustomPaint(
          painter: _DashedPainter(radius: 16, color: FpColors.success),
          child: Container(
            height: 130,
            padding: EdgeInsets.all(4),
            child: Stack(fit: StackFit.expand, children: [
              ClipRRect(borderRadius: BorderRadius.circular(12), child: Image.memory(img, fit: BoxFit.cover, gaplessPlayback: true)),
              Positioned(
                left: 6, bottom: 6,
                child: Container(
                  padding: EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                  decoration: BoxDecoration(color: Colors.black.withOpacity(.55), borderRadius: BorderRadius.circular(8)),
                  child: Row(mainAxisSize: MainAxisSize.min, children: [
                    Icon(Icons.check_circle_rounded, color: Colors.white, size: 14),
                    SizedBox(width: 4),
                    Text(tr(label), style: TextStyle(color: Colors.white, fontSize: 12.5, fontWeight: FontWeight.w600)),
                  ]),
                ),
              ),
            ]),
          ),
        ),
      );
    }
    final content = compact
        ? Row(children: [
            if (img != null)
              ClipRRect(borderRadius: BorderRadius.circular(6), child: Image.memory(img, width: 40, height: 40, fit: BoxFit.cover))
            else
              Icon(done ? Icons.check_circle_rounded : icon, color: done ? FpColors.success : FpColors.ink, size: 26),
            SizedBox(width: 14),
            Expanded(child: Text(done ? '$label · ajouté' : label, style: TextStyle(fontSize: 15.5, color: FpColors.ink))),
            Icon(done ? Icons.refresh_rounded : Icons.upload_rounded, color: FpColors.red),
          ])
        : Column(mainAxisAlignment: MainAxisAlignment.center, children: [
            Icon(done ? Icons.check_circle_rounded : icon, color: done ? FpColors.success : FpColors.ink, size: 34),
            SizedBox(height: 10),
            Text(done ? '$label ✓' : label, textAlign: TextAlign.center, style: TextStyle(fontSize: 15.5, color: FpColors.ink)),
          ]);
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(16),
      child: CustomPaint(
        painter: _DashedPainter(radius: 16, color: color),
        child: Container(
          height: compact ? 64 : 130,
          padding: EdgeInsets.symmetric(horizontal: compact ? 18 : 10),
          alignment: Alignment.center,
          child: content,
        ),
      ),
    );
  }
}

/// Cercle pointillé « photo » avec un « + » rouge.
class FpPhotoCircle extends StatelessWidget {
  final VoidCallback onTap;
  final bool done;
  final double size;
  /// Aperçu de la photo choisie.
  final Uint8List? preview;
  const FpPhotoCircle({super.key, required this.onTap, this.done = false, this.size = 124, this.preview});

  @override
  Widget build(BuildContext context) => GestureDetector(
        onTap: onTap,
        child: SizedBox(
          width: size + 8,
          height: size + 8,
          child: Stack(children: [
            CustomPaint(
              painter: _DashedPainter(radius: 0, circle: true, color: done ? FpColors.success : Color(0xFF9CA3AF)),
              child: SizedBox(
                width: size,
                height: size,
                child: preview != null
                    ? Padding(
                        padding: EdgeInsets.all(4),
                        child: ClipOval(child: Image.memory(preview!, fit: BoxFit.cover, width: size - 8, height: size - 8, gaplessPlayback: true)),
                      )
                    : Icon(done ? Icons.check_rounded : Icons.photo_camera_outlined, size: size * .28, color: done ? FpColors.success : FpColors.ink),
              ),
            ),
            Positioned(
              right: 0,
              bottom: size * .08,
              child: Container(
                width: size * .3,
                height: size * .3,
                decoration: BoxDecoration(color: FpColors.red, shape: BoxShape.circle),
                child: Icon(done ? Icons.edit : Icons.add, color: Colors.white, size: size * .19),
              ),
            ),
          ]),
        ),
      );
}

/// Libellé de champ obligatoire (« Pièce d'identité * »).
class FpRequiredLabel extends StatelessWidget {
  final String text;
  final String? hint;
  const FpRequiredLabel(this.text, {super.key, this.hint});

  @override
  Widget build(BuildContext context) => Padding(
        padding: EdgeInsets.only(bottom: 12, top: 4),
        child: Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
          Text.rich(TextSpan(children: [
            TextSpan(text: text, style: TextStyle(fontSize: 16, fontWeight: FontWeight.w500, color: FpColors.ink)),
            TextSpan(text: ' *', style: TextStyle(color: FpColors.red, fontSize: 16)),
          ])),
          if (hint != null) Padding(padding: EdgeInsets.only(top: 3), child: Text(hint!, style: TextStyle(fontSize: 14, color: FpColors.muted))),
        ]),
      );
}

/// Écran d'attente pour les services annoncés sur les maquettes mais pas
/// encore raccordés au back-office (Chat, Traduction, assistant JBEM…).
class FpComingSoonScreen extends StatelessWidget {
  final String title;
  final IconData icon;
  final String message;
  const FpComingSoonScreen({super.key, required this.title, required this.icon, this.message = 'Ce service arrive bientôt dans FlashPay.'});

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr(title))),
        body: Center(
          child: Padding(
            padding: EdgeInsets.all(32),
            child: Column(mainAxisSize: MainAxisSize.min, children: [
              FpPastille(icon, size: 88),
              SizedBox(height: 18),
              Text(tr(title), style: TextStyle(fontSize: 20, fontWeight: FontWeight.w700)),
              SizedBox(height: 8),
              Text(message, textAlign: TextAlign.center, style: TextStyle(color: FpColors.muted, fontSize: 15)),
            ]),
          ),
        ),
      );
}

/// Feuille « NFC entre téléphones bientôt disponible » avec repli sur le QR.
/// L'émulation de carte (HCE) Android n'est pas encore livrée (CDC §4.4).
void fpShowNfcSoon(BuildContext context, {String fallbackLabel = 'Utiliser le QR code', VoidCallback? fallback}) {
  showModalBottomSheet(
    context: context,
    builder: (ctx) => SafeArea(
      child: Padding(
        padding: EdgeInsets.fromLTRB(24, 0, 24, 24),
        child: Column(mainAxisSize: MainAxisSize.min, children: [
          FpPastille(Icons.nfc_rounded, size: 72),
          SizedBox(height: 14),
          Text(tr('NFC entre téléphones'), style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700)),
          SizedBox(height: 8),
          Text(
            tr('Le sans-contact de téléphone à téléphone arrive bientôt. En attendant, utilisez le QR code : c\'est aussi rapide.'),
            textAlign: TextAlign.center,
            style: TextStyle(color: FpColors.muted),
          ),
          SizedBox(height: 18),
          if (fallback != null)
            FpButton(fallbackLabel, red: true, onPressed: () {
              Navigator.pop(ctx);
              fallback();
            }),
        ]),
      ),
    ),
  );
}
