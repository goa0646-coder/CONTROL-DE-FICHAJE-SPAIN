<?php

class EncuestasTest extends Base
{
    public function testEncuestaAnonimaYEvaluacion(): void
    {
        $admin = $this->usuario(['rol' => 'admin']);
        $jefa = $this->usuario(['rol' => 'responsable']);
        $a = $this->usuario(['responsable_id' => $jefa['id']]);
        $b = $this->usuario(['responsable_id' => $jefa['id']]);
        $id = crear_encuesta($this->db, $admin, ['titulo' => 'Clima', 'anonima' => 1], [['¿Cómo estás?', 'escala', ''], ['¿Turno preferido?', 'opciones', "Mañana\nTarde"], ['Comentarios', 'texto', '']], [$a['id'], $b['id']]);
        $pa = pendientes_encuesta($this->db, $a);
        $this->assertCount(1, $pa);
        [$p1, $p2, $p3] = array_column(preguntas($this->db, $id), 'id');
        responder_encuesta($this->db, $a, (int)$pa[0]['id'], [$p1 => '4', $p2 => 'Tarde', $p3 => 'Bien']);
        responder_encuesta($this->db, $b, (int)pendientes_encuesta($this->db, $b)[0]['id'], [$p1 => '2', $p2 => 'Tarde', $p3 => '']);
        $r = resultados_encuesta($this->db, $id);
        $this->assertSame(3.0, (float)$r[$p1]['media']);
        $this->assertSame(['Tarde' => 2], $r[$p2]['cuentas']);
        $this->assertSame(['Bien'], $r[$p3]['textos']);
        $this->assertSame(0, (int)$this->db->query("SELECT COUNT(*) FROM encuesta_envios WHERE encuesta_id = $id AND asignacion_id IS NOT NULL")->fetchColumn(), 'anónima: sin rastro de quién');
        try { responder_encuesta($this->db, $a, (int)$pa[0]['id'], [$p1 => '5', $p2 => 'Tarde']); $this->fail('dos veces no'); } catch (DomainException) {}
        try { crear_encuesta($this->db, $admin, ['titulo' => 'X'], [['Elige', 'opciones', 'Solo una']], [$a['id']]); $this->fail('opciones mínimas'); } catch (DomainException) {}
        // Evaluación: la responde su responsable, sobre cada persona.
        $ev = crear_encuesta($this->db, $admin, ['titulo' => 'Desempeño', 'tipo' => 'evaluacion'], [['Puntualidad', 'escala', '']], [$a['id'], $b['id']]);
        $this->assertSame([], pendientes_encuesta($this->db, $a));
        $pj = pendientes_encuesta($this->db, $jefa);
        $this->assertCount(2, $pj);
        $q = (int)preguntas($this->db, $ev)[0]['id'];
        foreach ($pj as $x) responder_encuesta($this->db, $jefa, (int)$x['id'], [$q => $x['sobre_id'] == $a['id'] ? '5' : '3']);
        $this->assertSame(5.0, (float)resultados_encuesta($this->db, $ev, (int)$a['id'])[$q]['media']);
        $this->assertSame(4.0, (float)resultados_encuesta($this->db, $ev)[$q]['media']);
    }
}
