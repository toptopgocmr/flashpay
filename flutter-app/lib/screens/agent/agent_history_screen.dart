import 'package:flutter/material.dart';
import '../../services/agent_service.dart';
import 'agent_home_screen.dart';
import '../../l10n/l10n.dart';

class AgentHistoryScreen extends StatefulWidget {
  const AgentHistoryScreen({super.key});

  @override
  State<AgentHistoryScreen> createState() => _AgentHistoryScreenState();
}

class _AgentHistoryScreenState extends State<AgentHistoryScreen> {
  final _service = AgentService();
  final List<Map<String, dynamic>> _ops = [];
  int _page = 1;
  bool _loading = false;
  bool _end = false;

  @override
  void initState() {
    super.initState();
    _more();
  }

  Future<void> _more() async {
    if (_loading || _end) return;
    setState(() => _loading = true);
    try {
      final list = await _service.history(page: _page);
      setState(() {
        _ops.addAll(list);
        _page++;
        _end = list.length < 20;
      });
    } catch (_) {
      _end = true;
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) => Scaffold(
        appBar: AppBar(title: Text(tr('Historique agent'))),
        body: NotificationListener<ScrollNotification>(
          onNotification: (n) {
            if (n.metrics.pixels > n.metrics.maxScrollExtent - 200) _more();
            return false;
          },
          child: ListView(children: [
            ..._ops.map((o) => AgentOpTile(op: o)),
            if (_loading) Padding(padding: EdgeInsets.all(24), child: Center(child: CircularProgressIndicator())),
            if (!_loading && _ops.isEmpty) Padding(padding: EdgeInsets.all(32), child: Center(child: Text(tr('Aucune opération.')))),
          ]),
        ),
      );
}
