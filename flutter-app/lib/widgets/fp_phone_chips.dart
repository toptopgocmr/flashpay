import 'package:flutter/material.dart';
import '../config/theme.dart';
import '../l10n/l10n.dart';
import '../models/corridor.dart';
import '../services/payment_service.dart';

/// Choix du pays (drapeau + indicatif) pour les champs téléphone simples.
/// Charge la liste des pays couverts une seule fois (cache de PaymentService).
class FpDialPicker extends StatefulWidget {
  final FpCountry? value;
  final ValueChanged<FpCountry> onChanged;
  const FpDialPicker({super.key, required this.value, required this.onChanged});

  @override
  State<FpDialPicker> createState() => _FpDialPickerState();
}

class _FpDialPickerState extends State<FpDialPicker> {
  List<FpCountry> _countries = [];

  @override
  void initState() {
    super.initState();
    PaymentService().countries().then((c) {
      if (!mounted) return;
      setState(() => _countries = c);
      if (widget.value == null && c.isNotEmpty) {
        widget.onChanged(c.firstWhere((x) => x.iso == 'CG', orElse: () => c.first));
      }
    }).catchError((_) {});
  }

  Future<void> _pick() async {
    if (_countries.isEmpty) return;
    final q = ValueNotifier<String>('');
    final picked = await showModalBottomSheet<FpCountry>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (ctx) => SizedBox(
        height: MediaQuery.of(ctx).size.height * .7,
        child: Column(children: [
          Padding(
            padding: EdgeInsets.fromLTRB(16, 0, 16, 8),
            child: TextField(
              autofocus: false,
              decoration: InputDecoration(prefixIcon: Icon(Icons.search), hintText: tr('Rechercher un pays')),
              onChanged: (v) => q.value = v.trim().toLowerCase(),
            ),
          ),
          Expanded(
            child: ValueListenableBuilder<String>(
              valueListenable: q,
              builder: (_, text, __) {
                final list = _countries.where((c) => text.isEmpty || c.name.toLowerCase().contains(text) || c.dial.contains(text)).toList();
                return ListView.builder(
                  itemCount: list.length,
                  itemBuilder: (_, i) => ListTile(
                    leading: Text(list[i].flag, style: TextStyle(fontSize: 24)),
                    title: Text(list[i].name),
                    trailing: Text(list[i].dial, style: TextStyle(fontWeight: FontWeight.w600)),
                    onTap: () => Navigator.pop(ctx, list[i]),
                  ),
                );
              },
            ),
          ),
        ]),
      ),
    );
    if (picked != null) widget.onChanged(picked);
  }

  @override
  Widget build(BuildContext context) {
    final c = widget.value;
    return InkWell(
      onTap: _pick,
      borderRadius: BorderRadius.circular(12),
      child: Container(
        height: 52,
        padding: EdgeInsets.symmetric(horizontal: 10),
        decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12), border: Border.all(color: FpColors.line)),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          Text(c?.flag ?? '🌍', style: TextStyle(fontSize: 20)),
          SizedBox(width: 4),
          Text(c?.dial ?? '+242', style: TextStyle(fontWeight: FontWeight.w600)),
          Icon(Icons.arrow_drop_down, size: 20),
        ]),
      ),
    );
  }
}

/// Numéro international à partir du pays choisi et de la saisie ; null si invalide.
String? fpInternational(FpCountry? country, String raw) {
  final t = raw.trim();
  final digits = t.replaceAll(RegExp(r'\D'), '');
  if (t.startsWith('+') || t.startsWith('00')) {
    final d = t.startsWith('00') ? digits.substring(2) : digits;
    return d.length >= 9 && d.length <= 15 ? '+$d' : null;
  }
  if (country == null) {
    if (digits.length == 9 && digits.startsWith('0')) return '+242$digits';
    return null;
  }
  final local = country.normalizeLocal(digits);
  return country.isValidLocal(local) ? country.international(local) : null;
}

/// Saisie de plusieurs numéros sous forme de pastilles, avec l'indicatif du
/// pays : on choisit le pays, tape le numéro, « + » (ou Entrée) le valide et
/// affiche le nom du compte FlashPay s'il existe ; un appui sur ✕ le retire.
class FpPhoneChips extends StatefulWidget {
  final String label;
  final ValueChanged<List<String>> onChanged;
  final int max;
  const FpPhoneChips({super.key, required this.onChanged, this.label = 'Ajouter un numéro', this.max = 20});

  @override
  State<FpPhoneChips> createState() => _FpPhoneChipsState();
}

class _FpPhoneChipsState extends State<FpPhoneChips> {
  final _ctrl = TextEditingController();
  final List<String> _phones = [];
  final Map<String, String?> _names = {};
  FpCountry? _country;
  String? _error;

  void _add() {
    if (_ctrl.text.trim().isEmpty) return;
    final p = fpInternational(_country, _ctrl.text);
    if (p == null) {
      setState(() => _error = tr('Numéro invalide pour ce pays'));
      return;
    }
    if (_phones.contains(p)) {
      setState(() => _error = tr('Numéro déjà ajouté'));
      return;
    }
    if (_phones.length >= widget.max) {
      setState(() => _error = '${widget.max} ${tr('numéros maximum')}');
      return;
    }
    setState(() {
      _phones.add(p);
      _error = null;
      _ctrl.clear();
    });
    widget.onChanged(List.unmodifiable(_phones));
    PaymentService().lookup(p).then((r) {
      final u = r['flashpay_user'];
      if (mounted) setState(() => _names[p] = u is Map ? '${u['name']}' : null);
    }).catchError((_) {});
  }

  void _remove(String p) {
    setState(() => _phones.remove(p));
    widget.onChanged(List.unmodifiable(_phones));
  }

  @override
  Widget build(BuildContext context) {
    return Column(crossAxisAlignment: CrossAxisAlignment.stretch, mainAxisSize: MainAxisSize.min, children: [
      Row(crossAxisAlignment: CrossAxisAlignment.start, children: [
        FpDialPicker(value: _country, onChanged: (c) => setState(() => _country = c)),
        SizedBox(width: 8),
        Expanded(
          child: TextField(
            controller: _ctrl,
            keyboardType: TextInputType.phone,
            textInputAction: TextInputAction.done,
            onSubmitted: (_) => _add(),
            onChanged: (_) {
              if (_error != null) setState(() => _error = null);
            },
            decoration: InputDecoration(labelText: tr(widget.label), hintText: '06 123 45 67', errorText: _error, errorMaxLines: 2),
          ),
        ),
        SizedBox(width: 6),
        Padding(
          padding: EdgeInsets.only(top: 4),
          child: IconButton.filledTonal(onPressed: _add, icon: Icon(Icons.add_rounded), tooltip: tr('Ajouter')),
        ),
      ]),
      if (_phones.isNotEmpty) ...[
        SizedBox(height: 8),
        Wrap(spacing: 6, runSpacing: 6, children: [
          for (final p in _phones)
            InputChip(
              avatar: Icon(_names[p] != null ? Icons.verified_rounded : Icons.phone_android_rounded, size: 18, color: _names[p] != null ? FpColors.success : FpColors.muted),
              label: Text(_names[p] != null ? '${_names[p]} · $p' : p, style: TextStyle(fontSize: 13)),
              onDeleted: () => _remove(p),
            ),
        ]),
      ],
    ]);
  }
}
