import 'package:flutter/material.dart';
import '../config/theme.dart';
import '../models/corridor.dart';
import '../l10n/l10n.dart';

/// Valeur saisie : pays + numéro national normalisé.
class FpPhoneValue {
  final FpCountry country;
  final String local;
  const FpPhoneValue(this.country, this.local);

  bool get isValid => country.isValidLocal(local);
  String get international => country.international(local);
  FpOperator? get operator => country.operatorFor(local);
}

/// Champ téléphone international style Wave : drapeau + indicatif, numéro,
/// opérateur détecté automatiquement (MTN, Airtel, Orange, Moov…).
class FpPhoneInput extends StatefulWidget {
  final List<FpCountry> countries;
  final String label;
  final String? initialIso;
  final String? initialPhone;
  final ValueChanged<FpPhoneValue?> onChanged;
  final bool Function(FpCountry c)? countryFilter;

  /// Titre de la bande d'opérateurs du pays choisi (ex. « Payer avec », « Retirer vers »).
  final String operatorsLabel;

  const FpPhoneInput({
    super.key,
    required this.countries,
    required this.onChanged,
    this.label = 'Numéro de téléphone',
    this.initialIso,
    this.initialPhone,
    this.countryFilter,
    this.operatorsLabel = 'Opérateurs disponibles',
  });

  @override
  State<FpPhoneInput> createState() => _FpPhoneInputState();
}

class _FpPhoneInputState extends State<FpPhoneInput> with AutomaticKeepAliveClientMixin {
  late FpCountry _country;

  // Garde le champ en vie quand il sort de l'écran dans une ListView :
  // sinon il est recréé vide et efface le numéro saisi.
  @override
  bool get wantKeepAlive => true;
  final _ctrl = TextEditingController();

  List<FpCountry> get _list => widget.countries.where(widget.countryFilter ?? ((FpCountry _) => true)).toList();

  @override
  void initState() {
    super.initState();
    _country = _list.firstWhere((c) => c.iso == (widget.initialIso ?? 'CG'), orElse: () => _list.first);
    if (widget.initialPhone != null) {
      _applyRaw(widget.initialPhone!);
    }
    WidgetsBinding.instance.addPostFrameCallback((_) { if (mounted) _emit(); });
  }

  /// Si l'utilisateur colle un numéro international, on sélectionne le bon pays.
  void _applyRaw(String raw) {
    final digits = raw.replaceAll(RegExp(r'\D'), '');
    final intl = raw.trim().startsWith('+') || raw.trim().startsWith('00');
    if (intl || digits.length > 10) {
      final d = raw.trim().startsWith('00') ? digits.substring(2) : digits;
      for (final c in _list) {
        if (d.startsWith(c.dialDigits) && d.length > c.localLength) {
          _country = c;
          _ctrl.text = d.substring(c.dialDigits.length);
          return;
        }
      }
    }
    _ctrl.text = digits;
  }

  void _emit() {
    if (!mounted) return;
    final local = _country.normalizeLocal(_ctrl.text);
    widget.onChanged(local.isEmpty ? null : FpPhoneValue(_country, local));
    setState(() {});
  }

  Future<void> _pickCountry() async {
    final picked = await showModalBottomSheet<FpCountry>(
      context: context,
      isScrollControlled: true,
      showDragHandle: true,
      builder: (_) => _CountrySheet(countries: _list),
    );
    if (picked != null) {
      _country = picked;
      _emit();
    }
  }

  @override
  Widget build(BuildContext context) {
    super.build(context);
    final local = _country.normalizeLocal(_ctrl.text);
    final op = _country.operatorFor(local);
    final complete = _country.isValidLocal(local);

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(tr(widget.label), style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13)),
        SizedBox(height: 6),
        Row(
          children: [
            InkWell(
              onTap: _pickCountry,
              borderRadius: BorderRadius.circular(12),
              child: Container(
                height: 52,
                padding: EdgeInsets.symmetric(horizontal: 12),
                decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(12)),
                child: Row(children: [
                  Text(_country.flag, style: TextStyle(fontSize: 22)),
                  SizedBox(width: 6),
                  Text(_country.dial, style: TextStyle(fontWeight: FontWeight.w600)),
                  Icon(Icons.arrow_drop_down),
                ]),
              ),
            ),
            SizedBox(width: 8),
            Expanded(
              child: TextField(
                controller: _ctrl,
                keyboardType: TextInputType.phone,
                onChanged: (v) {
                  if (v.trim().startsWith('+') || v.trim().startsWith('00')) {
                    _applyRaw(v);
                    _ctrl.selection = TextSelection.collapsed(offset: _ctrl.text.length);
                  }
                  _emit();
                },
                decoration: InputDecoration(
                  hintText: '${'0' * (_country.localLength ~/ 2)}…',
                  suffixIcon: complete ? Icon(Icons.check_circle, color: FpColors.success) : null,
                ),
              ),
            ),
          ],
        ),
        SizedBox(height: 8),
        _OperatorStrip(
          label: widget.operatorsLabel,
          country: _country,
          detected: local.isEmpty ? null : op,
          onTap: _selectOperator,
        ),
        if (local.isNotEmpty) ...[
          SizedBox(height: 4),
          Row(children: [
            if (op == null) Text(tr('Opérateur non reconnu'), style: TextStyle(fontSize: 12, color: Colors.grey)),
            Spacer(),
            Text('${_country.name} · ${_country.currency}', style: TextStyle(fontSize: 12, color: Colors.grey)),
          ]),
        ],
      ],
    );
  }

  /// Toucher un opérateur alors que le champ est vide : pré-remplit son préfixe.
  void _selectOperator(FpOperator op) {
    if (_ctrl.text.trim().isNotEmpty || op.prefixes.isEmpty) return;
    _ctrl.text = op.prefixes.first;
    _ctrl.selection = TextSelection.collapsed(offset: _ctrl.text.length);
    _emit();
  }
}

/// Opérateurs mobile money du pays sélectionné. Une fois le numéro saisi,
/// l'opérateur détecté est mis en avant et les autres sont atténués.
class _OperatorStrip extends StatelessWidget {
  final String label;
  final FpCountry country;
  final FpOperator? detected;
  final ValueChanged<FpOperator> onTap;
  const _OperatorStrip({required this.label, required this.country, required this.detected, required this.onTap});

  @override
  Widget build(BuildContext context) {
    final seen = <String>{};
    final ops = country.operators.where((o) => seen.add(o.label)).toList();
    if (ops.isEmpty) return SizedBox.shrink();

    return Column(crossAxisAlignment: CrossAxisAlignment.start, children: [
      Text('$label · ${country.flag} ${country.name}', style: TextStyle(fontSize: 12, color: Colors.grey)),
      SizedBox(height: 6),
      Wrap(spacing: 6, runSpacing: 6, children: [
        for (final o in ops)
          GestureDetector(
            onTap: () => onTap(o),
            child: FpOperatorChip(
              label: o.label,
              selected: detected != null && detected!.label == o.label,
              dimmed: detected != null && detected!.label != o.label,
            ),
          ),
      ]),
    ]);
  }
}

class FpOperatorChip extends StatelessWidget {
  final String label;
  final bool selected;
  final bool dimmed;
  const FpOperatorChip({super.key, required this.label, this.selected = false, this.dimmed = false});

  Color get _color {
    final l = label.toLowerCase();
    if (l.contains('mtn')) return Color(0xFFFFCB05);
    if (l.contains('airtel')) return Color(0xFFE40000);
    if (l.contains('orange')) return Color(0xFFFF7900);
    if (l.contains('moov') || l.contains('flooz')) return Color(0xFF0066B3);
    if (l.contains('pesa') || l.contains('vodacom')) return Color(0xFFE60000);
    if (l.contains('flashpay')) return FpColors.navy;
    return Colors.blueGrey;
  }

  /// Logo officiel de l'opérateur (assets/images/operators), si disponible.
  String? get _logo {
    final l = label.toLowerCase();
    if (l.contains('mtn')) return 'assets/images/operators/mtn.png';
    if (l.contains('airtel')) return 'assets/images/operators/airtel.png';
    if (l.contains('orange')) return 'assets/images/operators/orange.png';
    if (l.contains('africell') || l.contains('afrimoney')) return 'assets/images/operators/africell.png';
    return null;
  }

  @override
  Widget build(BuildContext context) {
    final c = _color;
    final logo = _logo;
    final dark = c.computeLuminance() < 0.5;
    final fg = dark ? Colors.white : Colors.black87;
    return Opacity(
      opacity: dimmed ? 0.35 : 1,
      child: Container(
        padding: EdgeInsets.fromLTRB(logo != null ? 4 : 10, 3, 10, 3),
        decoration: BoxDecoration(
          color: logo != null ? Colors.white : c,
          borderRadius: BorderRadius.circular(20),
          border: selected
              ? Border.all(color: FpColors.navy, width: 2)
              : (logo != null ? Border.all(color: Color(0xFFE5E7EB)) : null),
        ),
        child: Row(mainAxisSize: MainAxisSize.min, children: [
          if (logo != null) ...[
            ClipRRect(
              borderRadius: BorderRadius.circular(6),
              child: Image.asset(logo, height: 22, width: 22, fit: BoxFit.contain),
            ),
            SizedBox(width: 6),
          ],
          if (selected) ...[Icon(Icons.check_circle, size: 14, color: logo != null ? FpColors.navy : fg), SizedBox(width: 4)],
          Text(tr(label), style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: logo != null ? FpColors.ink : fg)),
        ]),
      ),
    );
  }
}

class _CountrySheet extends StatefulWidget {
  final List<FpCountry> countries;
  const _CountrySheet({required this.countries});

  @override
  State<_CountrySheet> createState() => _CountrySheetState();
}

class _CountrySheetState extends State<_CountrySheet> {
  String _q = '';

  static const _zones = {'CEMAC': 'Afrique centrale (XAF)', 'UEMOA': "Afrique de l'Ouest (XOF)", 'RDC': 'RD Congo', 'GUINEE': 'Guinée'};

  @override
  Widget build(BuildContext context) {
    final list = widget.countries
        .where((c) => _q.isEmpty || c.name.toLowerCase().contains(_q) || c.dial.contains(_q) || c.iso.toLowerCase() == _q)
        .toList();
    final zones = <String, List<FpCountry>>{};
    for (final c in list) {
      zones.putIfAbsent(c.zone, () => []).add(c);
    }

    return SizedBox(
      height: MediaQuery.of(context).size.height * 0.75,
      child: Column(children: [
        Padding(
          padding: EdgeInsets.fromLTRB(16, 0, 16, 8),
          child: TextField(
            autofocus: true,
            decoration: InputDecoration(prefixIcon: Icon(Icons.search), hintText: tr('Rechercher un pays')),
            onChanged: (v) => setState(() => _q = v.trim().toLowerCase()),
          ),
        ),
        Expanded(
          child: ListView(
            children: [
              for (final z in zones.entries) ...[
                Padding(
                  padding: EdgeInsets.fromLTRB(16, 12, 16, 4),
                  child: Text(_zones[z.key] ?? z.key, style: TextStyle(fontSize: 12, fontWeight: FontWeight.w700, color: Colors.grey)),
                ),
                for (final c in z.value)
                  ListTile(
                    leading: Text(c.flag, style: TextStyle(fontSize: 26)),
                    title: Text(c.name),
                    subtitle: Text(c.operators.map((o) => o.label).join(' · '), maxLines: 1, overflow: TextOverflow.ellipsis),
                    trailing: Text(c.dial, style: TextStyle(fontWeight: FontWeight.w600)),
                    enabled: c.collect || c.payout,
                    onTap: () => Navigator.pop(context, c),
                  ),
              ],
            ],
          ),
        ),
      ]),
    );
  }
}
